<?php

/**
 * Stream detail view (Bootstrap 5). Per-stream page branched by $rStream['type']
 * (1=live, 2=movie, 3=created-channel, 4=radio, 5=episode). Header + optional
 * preview image, a live-only stats card (nav-pills Today/Week/Month/All Time with
 * four metric tiles each), then a main card whose nav-tabs are type-specific:
 *
 *   - Active Servers (all types): serverSide #datatable (ajax ./table) — streams /
 *     movies / episodes / radios all return CLEAN-JSON now, so every type is
 *     initialised with columns:[{data}] (never positional columnDefs). Per-row
 *     actions run through api(); the table soft-reloads every 5s (paused while a
 *     dropdown is open).
 *   - type 1: Stream Sources, Adaptive Link, Programme Guide, TV Archive, Recent Errors.
 *   - type 2: Movie Information (readonly form). type 5: Series + Episode Information.
 *   - type 3: Channel Guide (track table).
 *
 * Reached full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\TimeUtils;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamService;

?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= htmlspecialchars($rStream['stream_display_name']) ?></h4>
</div>

<?php if ($rImage): ?>
    <img class="img-fluid rounded mb-4" src="<?= htmlspecialchars($rImage) ?>" style="max-height:240px" onerror="this.style.display='none'" alt="">
<?php endif; ?>

<?php if ($rStream['type'] == 1): ?>
    <!-- Live stats — Today / This Week / This Month / All Time -->
    <div class="card mb-4">
        <div class="card-body">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'all' => 'All Time'] as $rKey => $rLabel): ?>
                    <li class="nav-item">
                        <button type="button" class="nav-link<?= $rKey === 'today' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#stat-<?= $rKey ?>" role="tab"><?= $rLabel ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-content p-0">
                <?php foreach (['today', 'week', 'month', 'all'] as $rType): ?>
                    <div class="tab-pane fade<?= $rType === 'today' ? ' show active' : '' ?>" id="stat-<?= $rType ?>" role="tabpanel">
                        <div class="row g-3 text-center">
                            <?php
                            $rTiles = [
                                ['tabler-trophy', 'Stream Rank', 0 < $rStreamStats[$rType]['rank'] ? '#' . $rStreamStats[$rType]['rank'] : 'N/A', 'primary'],
                                ['tabler-clock', 'Time Played', AdminHelpers::formatUptime($rStreamStats[$rType]['time']), 'info'],
                                ['tabler-player-play', 'Total Streams', number_format($rStreamStats[$rType]['connections'], 0), 'success'],
                                ['tabler-users', 'Total Users', number_format($rStreamStats[$rType]['users'], 0), 'warning'],
                            ];
                            foreach ($rTiles as $rTile):
                            ?>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-3 h-100">
                                        <span class="badge bg-label-<?= $rTile[3] ?> rounded p-2 mb-2"><i class="icon-base ti <?= $rTile[0] ?>"></i></span>
                                        <div class="text-body-secondary small"><?= $rTile[1] ?></div>
                                        <div class="h5 mb-0"><?= $rTile[2] ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <ul class="nav nav-tabs flex-wrap mb-4" role="tablist">
            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#servers" role="tab"><i class="icon-base ti tabler-server me-1"></i>Active Servers</button></li>
            <?php if ($rStream['type'] == 1): ?>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#sources" role="tab"><i class="icon-base ti tabler-list me-1"></i>Stream Sources</button></li>
                <?php if (0 < count($rAdaptiveLink)): ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#adaptive" role="tab"><i class="icon-base ti tabler-antenna me-1"></i>Adaptive Link</button></li>
                <?php endif; ?>
                <?php if (0 < count($rEPGData)): ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#guide" role="tab"><i class="icon-base ti tabler-calendar me-1"></i>Programme Guide</button></li>
                <?php endif; ?>
                <?php if (0 < $rStream['tv_archive_server_id'] && 0 < $rStream['tv_archive_duration']): ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#archive" role="tab"><i class="icon-base ti tabler-player-record me-1"></i>TV Archive</button></li>
                <?php endif; ?>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#errors" role="tab"><i class="icon-base ti tabler-alert-triangle me-1"></i>Recent Errors</button></li>
            <?php elseif ($rStream['type'] == 2): ?>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#information" role="tab"><i class="icon-base ti tabler-info-circle me-1"></i>Movie Information</button></li>
            <?php elseif ($rStream['type'] == 3): ?>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#sources" role="tab"><i class="icon-base ti tabler-list me-1"></i>Channel Guide</button></li>
            <?php elseif ($rStream['type'] == 5): ?>
                <?php if ($rSeries): ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#s-information" role="tab"><i class="icon-base ti tabler-device-tv me-1"></i>Series Information</button></li>
                <?php endif; ?>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#information" role="tab"><i class="icon-base ti tabler-info-circle me-1"></i>Episode Information</button></li>
            <?php endif; ?>
        </ul>

        <div class="tab-content p-0">
            <!-- Active Servers -->
            <div class="tab-pane fade show active" id="servers" role="tabpanel">
                <div class="table-responsive card-datatable">
                    <table id="datatable" class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th><?= $language::get('id') ?></th>
                                <th><?= $language::get('server') ?></th>
                                <th><?= $language::get('clients') ?></th>
                                <th><?= $rStream['type'] == 2 ? $language::get('status') : $language::get('uptime') ?></th>
                                <th><?= $language::get('actions') ?></th>
                                <th><?= $language::get('stream_info') ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <?php if ($rStream['type'] == 1): ?>
                <!-- Stream Sources -->
                <div class="tab-pane fade" id="sources" role="tabpanel">
                    <div class="table-responsive">
                        <table id="datatable-sources" class="table">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('order') ?></th>
                                    <th><?= $language::get('source') ?></th>
                                    <th class="text-center" style="width:320px;">Stream Info &nbsp;<button onClick="scanSources();" type="button" class="btn btn-sm btn-label-secondary"><i class="icon-base ti tabler-refresh"></i></button></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $i = 0;
                                foreach (json_decode($rStream['stream_source'], true) as $rSource):
                                    $i++;
                                    $rHost = parse_url($rSource)['host'];
                                    $rNumber = intval(explode('?', explode('.', explode('/', $rSource)[count(explode('/', $rSource)) - 1])[0])[0]);
                                    if (0 < $rNumber) {
                                        $rHost .= ' [ID: ' . $rNumber . ']';
                                    } elseif (in_array(strtolower(pathinfo($rSource)['extension'] ?? ''), array('ts', 'm3u8', 'mp4', 'mkv'))) {
                                        $rHost .= ' [' . explode('?', explode('/', $rSource)[count(explode('/', $rSource)) - 1])[0] . ']';
                                    }
                                ?>
                                    <tr class="stream_info" data-id="<?= $i - 1 ?>">
                                        <td class="text-center">
                                            <button onClick="overrideSource(<?= intval(RequestManager::get('id')) ?>, <?= $i - 1 ?>);" type="button" title="<?= $language::get('override_source') ?>" class="btn btn-label-info btn-sm"><?= $i ?></button>
                                        </td>
                                        <td><span><?= htmlspecialchars($rHost) ?></span></td>
                                        <td class="text-center" id="stream_info_<?= $i - 1 ?>" style="width:320px;">
                                            <span class="text-body-secondary">Not scanned</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (0 < count($rAdaptiveLink)):
                    $rAdaptiveLink = array_merge(array($rStream['id']), $rAdaptiveLink);
                    $rAdaptiveInfo = $rStreamNames = array();
                    $db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rAdaptiveLink)) . ');');
                    foreach ($db->get_rows() as $rRow) {
                        $rStreamNames[$rRow['id']] = $rRow['stream_display_name'];
                    }
                    $db->query('SELECT `stream_id`, `stream_info`, `progress_info` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rAdaptiveLink)) . ') AND `stream_info` IS NOT NULL AND `pid` IS NOT NULL AND `pid` > 0 GROUP BY `stream_id`;');
                    foreach ($db->get_rows() as $rRow) {
                        $rAdaptiveInfo[$rRow['stream_id']] = array(json_decode($rRow['stream_info'], true), json_decode($rRow['progress_info'], true));
                    }
                ?>
                    <!-- Adaptive Link -->
                    <div class="tab-pane fade" id="adaptive" role="tabpanel">
                        <div class="table-responsive">
                            <table id="datatable-adaptive" class="table">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('id') ?></th>
                                        <th><?= $language::get('stream_name') ?></th>
                                        <th class="text-center"><?= $language::get('bandwidth') ?></th>
                                        <th class="text-center" style="width:300px;"><?= $language::get('stream_info') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rAdaptiveLink as $rAdaptiveID):
                                        list($rStreamInfo, $rProgressInfo) = ($rAdaptiveInfo[$rAdaptiveID] ?: array());
                                        if (!isset($rStreamInfo['codecs']['video'])) {
                                            $rStreamInfo['codecs']['video'] = array('width' => '?', 'height' => '?', 'codec_name' => 'N/A', 'r_frame_rate' => '--');
                                        }
                                        if (!isset($rStreamInfo['codecs']['audio'])) {
                                            $rStreamInfo['codecs']['audio'] = array('codec_name' => 'N/A');
                                        }
                                        if ($rStreamInfo['bitrate'] == 0) {
                                            $rStreamInfo['bitrate'] = '?';
                                        }
                                        $rSpeed = isset($rProgressInfo['speed']) ? floor($rProgressInfo['speed'] * 100) / 100 . 'x' : '1x';
                                        $rFPS = null;
                                        if (isset($rProgressInfo['fps'])) {
                                            $rFPS = intval($rProgressInfo['fps']);
                                        } elseif (isset($rStreamInfo['codecs']['video']['r_frame_rate'])) {
                                            $rFPS = intval($rStreamInfo['codecs']['video']['r_frame_rate']);
                                        }
                                        if ($rFPS) {
                                            if (1000 <= $rFPS) {
                                                $rFPS = intval($rFPS / 1000);
                                            }
                                            $rFPS .= ' FPS';
                                        } else {
                                            $rFPS = '--';
                                        }
                                    ?>
                                        <tr>
                                            <td class="text-center">
                                                <a href="stream_view?id=<?= $rAdaptiveID ?>" class="btn btn-label-info btn-sm"><?= $rAdaptiveID ?></a>
                                            </td>
                                            <td><?= htmlspecialchars($rStreamNames[$rAdaptiveID] ?: 'Not Available') ?></td>
                                            <td class="text-center"><?= number_format(floatval($rStreamInfo['bitrate']), 0) ?></td>
                                            <td class="text-center" style="width:300px;">
                                                <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                    <span class="badge bg-label-secondary"><?= number_format(($rStreamInfo['bitrate'] === '?' ? 0 : $rStreamInfo['bitrate']) / 1024, 0) ?> Kbps</span>
                                                    <span class="badge bg-label-primary"><?= htmlspecialchars($rStreamInfo['codecs']['video']['width'] . ' x ' . $rStreamInfo['codecs']['video']['height']) ?></span>
                                                    <span class="badge bg-label-info"><?= htmlspecialchars($rStreamInfo['codecs']['video']['codec_name']) ?></span>
                                                    <span class="badge bg-label-success"><?= htmlspecialchars($rStreamInfo['codecs']['audio']['codec_name']) ?></span>
                                                    <?php if (!$rCreated): ?><span class="badge bg-label-secondary"><?= htmlspecialchars($rSpeed) ?></span><?php endif; ?>
                                                    <span class="badge bg-label-secondary"><?= htmlspecialchars($rFPS) ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (0 < count($rEPGData)):
                    $rAvailable = false;
                    $db->query('SELECT `server_id`, `direct_source`, `monitor_pid`, `pid`, `stream_status`, `on_demand` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`id` = ? AND `server_id` IS NOT NULL;', $rStream['id']);
                    if (0 < $db->num_rows()) {
                        foreach ($db->get_rows() as $rStreamRow) {
                            if ($rStreamRow['server_id'] && !$rStreamRow['direct_source']) {
                                $rAvailable = true;
                                break;
                            }
                        }
                    }
                ?>
                    <!-- Programme Guide -->
                    <div class="tab-pane fade" id="guide" role="tabpanel">
                        <div class="list-group" style="max-height:520px;overflow-y:auto;">
                            <?php
                            $rPrevDate = date('Y-m-d');
                            foreach ($rEPGData as $rEPGItem):
                                if (date('Y-m-d', $rEPGItem['start']) != $rPrevDate) {
                                    $rPrevDate = date('Y-m-d', $rEPGItem['start']);
                                    echo '<h6 class="text-body-secondary mt-3 mb-2">' . date('l jS', $rEPGItem['start']) . '</h6>';
                                }
                            ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="fw-medium"><?= htmlspecialchars($rEPGItem['title']) ?></div>
                                            <div class="text-body-secondary small mt-1"><?= htmlspecialchars($rEPGItem['description']) ?></div>
                                        </div>
                                        <div class="text-nowrap d-flex align-items-center gap-2">
                                            <span class="badge bg-label-info"><?= date('H:i', $rEPGItem['start']) ?> - <?= date('H:i', $rEPGItem['end']) ?></span>
                                            <?php if ($rAvailable): ?>
                                                <a href="record?id=<?= intval($rStream['id']) ?>&programme=<?= intval($rEPGItem['id']) ?>" class="btn btn-sm btn-label-danger" title="<?= $language::get('record') ?>"><i class="icon-base ti tabler-player-record"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (0 < $rStream['tv_archive_server_id'] && 0 < $rStream['tv_archive_duration']):
                    $rArchive = StreamService::getArchive($rStream['id']);
                ?>
                    <!-- TV Archive -->
                    <div class="tab-pane fade" id="archive" role="tabpanel">
                        <div class="table-responsive">
                            <table id="datatable-archive" class="table">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('date') ?></th>
                                        <th><?= $language::get('title') ?></th>
                                        <th class="text-center"><?= $language::get('status') ?></th>
                                        <th class="text-center"><?= $language::get('player') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rArchive as $rItem):
                                        $rDuration = $rItem['end'] - $rItem['start'];
                                        $rItem['stream_id'] = RequestManager::get('id');
                                    ?>
                                        <tr>
                                            <td class="text-center">
                                                <?= date($rSettings['date_format'], $rItem['start']) ?><br>
                                                <?= date('H:i:s', $rItem['start']) ?> - <?= date('H:i:s', $rItem['end']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($rItem['title']) ?></td>
                                            <td class="text-center">
                                                <?php if ($rItem['in_progress']): ?>
                                                    <span class="badge bg-label-info"><?= $language::get('in_progress') ?></span>
                                                <?php elseif ($rItem['complete']): ?>
                                                    <span class="badge bg-label-success"><?= $language::get('complete') ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-label-warning"><?= $language::get('incomplete') ?></span>
                                                <?php endif; ?>
                                                <a href="record?archive=<?= urlencode(base64_encode(json_encode($rItem))) ?>" class="btn btn-sm btn-label-danger" title="<?= $language::get('save_to_vod') ?>"><i class="icon-base ti tabler-player-record"></i></a>
                                            </td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-icon btn-label-info" onclick="player(<?= intval($rStream['id']) ?>, <?= intval($rItem['start']) ?>, <?= intval($rDuration / 60) ?>);"><i class="icon-base ti tabler-player-play"></i></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Errors -->
                <div class="tab-pane fade" id="errors" role="tabpanel">
                    <div class="table-responsive">
                        <table id="datatable-errors" class="table">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:180px;"><?= $language::get('date') ?></th>
                                    <th><?= $language::get('message') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (StreamRepository::getErrors($rStream['id']) as $rItem): ?>
                                    <tr>
                                        <td class="text-center"><?= date($rSettings['datetime_format'], $rItem['date']) ?></td>
                                        <td onClick="showError(this);" style="cursor:pointer;"><?= htmlspecialchars($rItem['error']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($rStream['type'] == 2): ?>
                <!-- Movie Information -->
                <div class="tab-pane fade" id="information" role="tabpanel">
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="plot"><?= $language::get('plot') ?></label>
                        <div class="col-md-10"><textarea readonly rows="6" class="form-control" id="plot" name="plot"><?= htmlspecialchars($rProperties['plot']) ?></textarea></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="cast"><?= $language::get('cast') ?></label>
                        <div class="col-md-10"><input readonly type="text" class="form-control" id="cast" name="cast" value="<?= htmlspecialchars($rProperties['cast']) ?>"></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="director"><?= $language::get('director') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control" id="director" name="director" value="<?= htmlspecialchars($rProperties['director']) ?>"></div>
                        <label class="col-md-2 col-form-label" for="genre"><?= $language::get('genres') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control" id="genre" name="genre" value="<?= htmlspecialchars($rProperties['genre']) ?>"></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="release_date"><?= $language::get('release_date') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="release_date" name="release_date" value="<?= htmlspecialchars($rProperties['release_date']) ?>"></div>
                        <label class="col-md-2 col-form-label" for="episode_run_time"><?= $language::get('runtime') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="<?= TimeUtils::secondsToTime(intval($rProperties['episode_run_time']) * 60, false) ?>"></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="youtube_trailer"><?= $language::get('youtube_trailer') ?></label>
                        <div class="col-md-4">
                            <div class="input-group">
                                <input readonly type="text" class="form-control text-center" id="youtube_trailer" name="youtube_trailer" value="<?= htmlspecialchars($rProperties['youtube_trailer']) ?>">
                                <button type="button" onClick="openYouTube(this)" class="btn btn-label-primary"><i class="icon-base ti tabler-eye"></i></button>
                            </div>
                        </div>
                        <label class="col-md-2 col-form-label" for="rating"><?= $language::get('rating') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="rating" name="rating" value="<?= htmlspecialchars($rProperties['rating']) ?>"></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="country"><?= $language::get('country') ?></label>
                        <div class="col-md-10"><input readonly type="text" class="form-control" id="country" name="country" value="<?= htmlspecialchars($rProperties['country']) ?>"></div>
                    </div>
                </div>

            <?php elseif ($rStream['type'] == 5): ?>
                <?php if ($rSeries): ?>
                    <!-- Series Information -->
                    <div class="tab-pane fade" id="s-information" role="tabpanel">
                        <div class="row mb-3">
                            <label class="col-md-2 col-form-label" for="plot"><?= $language::get('plot') ?></label>
                            <div class="col-md-10"><textarea readonly rows="6" class="form-control" id="plot" name="plot"><?= htmlspecialchars($rSeries['plot']) ?></textarea></div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-2 col-form-label" for="cast"><?= $language::get('cast') ?></label>
                            <div class="col-md-10"><input readonly type="text" class="form-control" id="cast" name="cast" value="<?= htmlspecialchars($rSeries['cast']) ?>"></div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-2 col-form-label" for="director"><?= $language::get('director') ?></label>
                            <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="director" name="director" value="<?= htmlspecialchars($rSeries['director']) ?>"></div>
                            <label class="col-md-2 col-form-label" for="genre"><?= $language::get('genres') ?></label>
                            <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="genre" name="genre" value="<?= htmlspecialchars($rSeries['genre']) ?>"></div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-2 col-form-label" for="release_date"><?= $language::get('release_date') ?></label>
                            <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="release_date" name="release_date" value="<?= htmlspecialchars($rSeries['release_date']) ?>"></div>
                            <label class="col-md-2 col-form-label" for="episode_run_time"><?= $language::get('runtime') ?></label>
                            <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="<?= TimeUtils::secondsToTime(intval($rProperties['episode_run_time']) * 60, false) ?>"></div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-2 col-form-label" for="youtube_trailer"><?= $language::get('youtube_trailer_label') ?></label>
                            <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="youtube_trailer" name="youtube_trailer" value="<?= htmlspecialchars($rSeries['youtube_trailer']) ?>"></div>
                            <label class="col-md-2 col-form-label" for="rating"><?= $language::get('rating') ?></label>
                            <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="rating" name="rating" value="<?= htmlspecialchars($rSeries['rating']) ?>"></div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Episode Information -->
                <div class="tab-pane fade" id="information" role="tabpanel">
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="plot"><?= $language::get('plot') ?></label>
                        <div class="col-md-10"><textarea readonly rows="6" class="form-control" id="plot" name="plot"><?= htmlspecialchars($rProperties['plot']) ?></textarea></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="release_date"><?= $language::get('release_date') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="release_date" name="release_date" value="<?= htmlspecialchars($rProperties['release_date']) ?>"></div>
                        <label class="col-md-2 col-form-label" for="episode_run_time"><?= $language::get('runtime') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="<?= TimeUtils::secondsToTime(intval($rProperties['duration_secs']), false) ?>"></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-2 col-form-label" for="rating"><?= $language::get('rating') ?></label>
                        <div class="col-md-4"><input readonly type="text" class="form-control text-center" id="rating" name="rating" value="<?= htmlspecialchars($rProperties['rating']) ?>"></div>
                    </div>
                </div>

            <?php elseif ($rStream['type'] == 3 && $rCCInfo): ?>
                <!-- Channel Guide -->
                <div class="tab-pane fade" id="sources" role="tabpanel">
                    <div class="table-responsive">
                        <table id="datatable-sources" class="table">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('position_header') ?></th>
                                    <th><?= $language::get('filename') ?></th>
                                    <th class="text-center"><?= $language::get('start') ?></th>
                                    <th class="text-center"><?= $language::get('finish') ?></th>
                                    <th class="text-center"><?= $language::get('duration') ?></th>
                                    <th class="text-center"><?= $language::get('stream_info') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rCCInfo as $rTrack):
                                    $rFilename = pathinfo(json_decode($rStream['stream_source'], true)[$rTrack['position']])['filename'];
                                    $rTrack['seconds'] = intval(explode('.', $rTrack['seconds'])[0]);
                                    $rOffset = $rTrack['start'] - $rSeconds;
                                    $rActualStart = time() + $rOffset;
                                    $rActualFinish = $rActualStart + $rTrack['seconds'];
                                    if (86400 <= $rTrack['seconds']) {
                                        $rDuration = sprintf('%02dd %02dh %02dm', $rTrack['seconds'] / 86400, ($rTrack['seconds'] / 3600) % 24, ($rTrack['seconds'] / 60) % 60);
                                    } else {
                                        $rDuration = sprintf('%02dh %02dm %02ds', $rTrack['seconds'] / 3600, ($rTrack['seconds'] / 60) % 60, $rTrack['seconds'] % 60);
                                    }
                                    if (!isset($rTrack['stream_info']['codecs']['video'])) {
                                        $rTrack['stream_info']['codecs']['video'] = array('width' => '?', 'height' => '?', 'codec_name' => 'N/A', 'r_frame_rate' => '--');
                                    }
                                    if (!isset($rTrack['stream_info']['codecs']['audio'])) {
                                        $rTrack['stream_info']['codecs']['audio'] = array('codec_name' => 'N/A');
                                    }
                                    if ($rTrack['stream_info']['bitrate'] == 0) {
                                        $rTrack['stream_info']['bitrate'] = '?';
                                    }
                                    $rFPS = null;
                                    if (isset($rTrack['stream_info']['codecs']['video']['r_frame_rate'])) {
                                        $rFPS = intval($rTrack['stream_info']['codecs']['video']['r_frame_rate']);
                                    }
                                    if ($rFPS) {
                                        if (1000 <= $rFPS) {
                                            $rFPS = intval($rFPS / 1000);
                                        }
                                        $rFPS .= ' FPS';
                                    } else {
                                        $rFPS = '--';
                                    }
                                    $rPlaying = ($rTrack['start'] <= $rSeconds && $rSeconds < $rTrack['finish']);
                                ?>
                                    <tr>
                                        <td class="text-center">
                                            <?php if ($rPlaying): ?>
                                                <span class="btn btn-label-info btn-sm" title="<?= $language::get('playing_now') ?>"><?= $rTrack['position'] + 1 ?></span>
                                            <?php else: ?>
                                                <span class="btn btn-label-secondary btn-sm"><?= $rTrack['position'] + 1 ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($rFilename) ?></td>
                                        <td class="text-center"><?= date('H:i:s', $rActualStart) ?></td>
                                        <td class="text-center"><?= date('H:i:s', $rActualFinish) ?></td>
                                        <td class="text-center"><span class="badge bg-label-success"><?= htmlspecialchars($rDuration) ?></span></td>
                                        <td class="text-center">
                                            <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                <span class="badge bg-label-secondary"><?= number_format(intval($rTrack['stream_info']['bitrate']) / 1024, 0) ?> Kbps</span>
                                                <span class="badge bg-label-primary"><?= htmlspecialchars($rTrack['stream_info']['codecs']['video']['width'] . ' x ' . $rTrack['stream_info']['codecs']['video']['height']) ?></span>
                                                <span class="badge bg-label-info"><?= htmlspecialchars($rTrack['stream_info']['codecs']['video']['codec_name']) ?></span>
                                                <span class="badge bg-label-success"><?= htmlspecialchars($rTrack['stream_info']['codecs']['audio']['codec_name']) ?></span>
                                                <span class="badge bg-label-secondary"><?= htmlspecialchars($rFPS) ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Player modal -->
<div class="modal fade" id="playerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('player') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="player-frame" src="about:blank" style="width:100%;height:60vh;border:0"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Error modal -->
<div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0">Stream Error</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><pre id="error-body" class="mb-0 text-wrap"></pre></div>
        </div>
    </div>
</div>

<!-- Live connections modal -->
<div class="modal fade" id="liveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('live_connections') ?: 'Live Connections' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table id="datatable-live" class="table" style="width:100%">
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
                                <th class="text-center"><?= $language::get('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var toast = window.xcToast || function() {};
        var confirmBox = function(text) { return window.xcConfirm ? window.xcConfirm(text) : Promise.resolve(window.confirm(text)); };

        var rType = <?= (int) $rStream['type'] ?>;
        var streamId = <?= (int) $rStream['id'] ?>;
        var lang = {
            start: <?= json_encode($language::get('start') ?: 'Start') ?>,
            stop: <?= json_encode($language::get('stop') ?: 'Stop') ?>,
            restart: <?= json_encode($language::get('restart') ?: 'Restart') ?>,
            kill: <?= json_encode($language::get('kill') ?: 'Kill Connections') ?>,
            encode: <?= json_encode($language::get('encode') ?: 'Encode') ?>,
            edit: <?= json_encode($language::get('edit')) ?>,
            del: <?= json_encode($language::get('delete')) ?>,
            error: <?= json_encode($language::get('error_occured')) ?>,
            movieStart: <?= json_encode($language::get('movie_encode_started')) ?>,
            movieStop: <?= json_encode($language::get('movie_encode_stopped')) ?>,
            movieDelete: <?= json_encode($language::get('movie_delete_confirmed')) ?>,
            episodeStart: <?= json_encode($language::get('episode_encoding_start')) ?>,
            episodeStop: <?= json_encode($language::get('episode_encoding_stop')) ?>,
            episodeDelete: <?= json_encode($language::get('episode_deleted')) ?>
        };

        // StatusBadge maps.
        var STREAM = { '-1': ['secondary', 'No Server'], '0': ['dark', 'Stopped'], '1': ['success', 'Online'], '2': ['warning', 'Starting'], '3': ['danger', 'Down'], '4': ['info', 'On Demand'], '5': ['primary', 'Direct Source'], '6': ['primary', 'Converting'], '7': ['danger', 'Proxy Down'] };
        var RADIO = { '-1': ['secondary', 'NO SERVERS'], '0': ['dark', 'STOPPED'], '1': ['success', 'ONLINE'], '2': ['warning', 'STARTING'], '3': ['danger', 'DOWN'], '4': ['info', 'ON DEMAND'], '5': ['dark', 'DIRECT SOURCE'] };
        var VOD = { '-1': ['secondary', 'No Server Selected'], '0': ['dark', 'Not Encoded'], '1': ['success', 'Encoded'], '2': ['warning', 'Encoding'], '3': ['primary', 'Direct Source'], '4': ['danger', 'Down'], '5': ['info', 'Direct Stream'] };

        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var fmtUptime = function(sec) {
            sec = Math.max(0, Math.floor(sec || 0));
            if (sec >= 86400) { return pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm'; }
            return pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's';
        };
        var failsDot = function(f) {
            if (!f || !f[0]) { return ''; }
            var count = f[0], last = f[1] || 0, color;
            if (count <= 2) { color = 'success'; } else if (count <= 4 || last > 21600) { color = 'info'; } else if (count <= 144 || last > 600) { color = 'warning'; } else { color = 'danger'; }
            return '<i class="icon-base ti tabler-alert-circle text-' + color + ' me-1" title="' + count + ' restarts"></i>';
        };
        var isVod = (rType === 2 || rType === 5);
        var isRunning = function(row) {
            if (isVod) { return row.status === 2; }
            return row.status === 1 || row.status === 2 || row.status === 3 || row.status === 5 || row.on_demand;
        };

        // ----- cell renderers -----
        function idCell(d, t, row) { return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body">' + esc(d) + '</a>'; }

        function serverCell(d, t, row) {
            if (!d) { return '<span class="text-body-secondary">No Server</span>'; }
            var html = row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
            if (row.server_offline) { html += ' <i class="icon-base ti tabler-alert-triangle text-danger" title="Server offline"></i>'; }
            var host = row.source_host || row.source_label;
            if (host) { html += '<br><small class="text-body-secondary">' + esc(host) + '</small>'; }
            return html;
        }

        function clientsCell(d, t, row) {
            if (d > 0) { return '<a href="javascript:void(0);" class="badge bg-label-info" onclick="viewLiveConnections(' + Number(row.id) + ',' + Number(row.server_col_id) + ')">' + Number(d).toLocaleString() + '</a>'; }
            return '<span class="badge bg-label-secondary">' + (d || 0) + '</span>';
        }

        function statusCell(d, t, row) {
            if (isVod) { var v = VOD[String(d)] || ['secondary', '']; return '<span class="badge bg-label-' + v[0] + '">' + esc(v[1]) + '</span>'; }
            if (rType === 4) {
                if (d === 1) { return '<span class="badge bg-label-success">' + esc(fmtUptime(row.uptime)) + '</span>'; }
                var r = RADIO[String(d)] || ['secondary', '']; return '<span class="badge bg-label-' + r[0] + '">' + esc(r[1]) + '</span>';
            }
            var dot = failsDot(row.fails);
            if (d === 1) { return dot + '<span class="badge bg-label-success">' + esc(fmtUptime(row.uptime)) + '</span>'; }
            if (d === 6) { return '<span class="badge bg-label-primary">' + (row.encode_pct != null ? esc(row.encode_pct) + '% DONE' : 'Converting') + '</span>'; }
            var s = STREAM[String(d)] || ['secondary', ''];
            return dot + '<span class="badge bg-label-' + s[0] + '">' + esc(s[1]) + '</span>';
        }

        function actionsCell(d, t, row) {
            var sid = row.server_col_id, id = row.id, items = '';
            var item = function(sub, label, cls) { return '<a class="dropdown-item ' + (cls || '') + ' js-act" href="javascript:void(0);" data-sub="' + esc(sub) + '" data-id="' + esc(id) + '" data-server="' + esc(sid) + '">' + esc(label) + '</a>'; };
            if (isVod) {
                if (row.status === 2) { items += item('stop', lang.stop); }
                else if (row.status !== 3 && row.status !== 5) { items += item('start', lang.encode); }
                if (row.clients > 0) { items += item('purge', lang.kill); }
                items += item('delete', lang.del, 'text-danger');
            } else if (isRunning(row)) {
                items += item('stop', lang.stop);
                items += item('restart', lang.restart);
                items += item('purge', lang.kill);
            } else {
                items += item('start', lang.start);
            }
            if (row.notes) { items = '<h6 class="dropdown-header text-wrap" style="max-width:18rem" title="' + esc(row.notes) + '">' + esc(row.notes) + '</h6><div class="dropdown-divider"></div>' + items; }
            if (!items) { return ''; }
            return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
        }

        function infoCell(d) {
            if (!d) { return '<small class="text-body-secondary">—</small>'; }
            var html = '<div class="d-flex flex-wrap gap-1">';
            if (rType === 4) {
                html += '<span class="badge bg-label-secondary">' + esc(d.bitrate) + ' Kbps</span>';
                html += '<span class="badge bg-label-success">' + esc(d.audio_codec) + '</span>';
                html += '<span class="badge bg-label-info">' + esc(d.speed) + '</span>';
            } else if (isVod) {
                html += '<span class="badge bg-label-secondary">' + esc(Number(d.bitrate).toLocaleString()) + ' Kbps</span>';
                html += '<span class="badge bg-label-primary">' + esc(d.width) + '×' + esc(d.height) + '</span>';
                html += '<span class="badge bg-label-info">' + esc(d.video_codec) + '</span>';
                html += '<span class="badge bg-label-success">' + esc(d.audio_codec) + '</span>';
                if (d.duration) { html += '<span class="badge bg-label-secondary">' + esc(d.duration) + '</span>'; }
            } else {
                html += '<span class="badge bg-label-secondary">' + esc(d.bitrate) + ' Kbps</span>';
                html += '<span class="badge bg-label-primary">' + esc(d.resolution) + '</span>';
                html += '<span class="badge bg-label-info">' + esc(d.video) + '</span>';
                html += '<span class="badge bg-label-success">' + esc(d.audio) + '</span>';
                html += '<span class="badge bg-label-secondary">' + esc(d.speed) + '</span>';
                html += '<span class="badge bg-label-secondary">' + esc(d.fps) + '</span>';
            }
            return html + '</div>';
        }

        // ----- main servers table (clean-JSON for every type) -----
        var table = $('#datatable').DataTable({
            processing: true,
            serverSide: true,
            ordering: false,
            paging: false,
            searching: false,
            info: false,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = (rType === 2) ? 'movies' : (rType === 4) ? 'radios' : (rType === 5) ? 'episodes' : 'streams';
                    d.stream_id = streamId;
                    d.single = true;
                    if (rType === 3) { d.created = true; }
                }
            },
            columns: [
                { data: 'display_id', className: 'text-center', render: idCell },
                { data: 'server_name', render: serverCell },
                { data: 'clients', className: 'text-center', render: clientsCell },
                { data: 'status', className: 'text-center text-nowrap', render: statusCell },
                { data: null, className: 'text-center', render: actionsCell },
                { data: 'info', render: infoCell }
            ],
            language: { emptyTable: 'Loading information...' }
        });

        // Row actions -> api().
        $('#datatable tbody').on('click', '.js-act', function() {
            api(this.getAttribute('data-id'), this.getAttribute('data-server'), this.getAttribute('data-sub'));
        });

        // Soft reload every 5s, paused while a dropdown menu is open.
        function reloadStream() {
            if (!document.querySelector('.dropdown-menu.show')) {
                table.ajax.reload(null, false);
            }
            setTimeout(reloadStream, 5000);
        }
        setTimeout(reloadStream, 5000);

        // ----- start/stop/restart/kill/purge/delete -----
        window.api = function(id, serverId, sub) {
            var run = function() {
                var action = (rType === 2) ? 'movie' : (rType === 5) ? 'episode' : 'stream';
                fetch('./api?action=' + action + '&sub=' + encodeURIComponent(sub) + '&stream_id=' + encodeURIComponent(id) + '&server_id=' + encodeURIComponent(serverId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || data.result !== true) { toast(lang.error, 'error'); return; }
                        var msg = '';
                        if (sub === 'start') { msg = (rType === 2) ? lang.movieStart : (rType === 5) ? lang.episodeStart : 'Stream successfully started.'; }
                        else if (sub === 'stop') { msg = (rType === 2) ? lang.movieStop : (rType === 5) ? lang.episodeStop : 'Stream successfully stopped.'; }
                        else if (sub === 'restart') { msg = 'Stream successfully restarted.'; }
                        else if (sub === 'delete') { msg = (rType === 2) ? lang.movieDelete : lang.episodeDelete; }
                        else if (sub === 'kill') { msg = 'Connection has been killed.'; }
                        else if (sub === 'purge') { msg = 'Connections have been killed.'; }
                        toast(msg, 'success');
                        table.ajax.reload(null, false);
                        var lm = document.getElementById('liveModal');
                        if ((sub === 'kill' || sub === 'purge') && lm && lm.classList.contains('show')) { $('#datatable-live').DataTable().ajax.reload(null, false); }
                    })
                    .catch(function() { toast(lang.error, 'error'); });
            };
            if (sub === 'purge' || sub === 'delete') {
                confirmBox(sub === 'delete' ? lang.del + '?' : 'Are you sure you want to kill all connections?').then(function(ok) { if (ok) { run(); } });
            } else { run(); }
        };

        // ----- player (type-specific URL) -----
        window.player = function(id, a, b) {
            var src;
            if (rType === 1 || rType === 3) {
                src = (a && b) ? ('./player?type=timeshift&id=' + id + '&start=' + a + '&duration=' + b) : ('./player?type=live&id=' + id);
            } else if (rType === 2) {
                src = './player?type=movie&id=' + id + '&container=' + (a || '');
            } else {
                src = './player?type=series&id=' + id + '&container=' + (a || '');
            }
            document.getElementById('player-frame').src = src;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('playerModal')).show();
        };
        document.getElementById('playerModal').addEventListener('hidden.bs.modal', function() { document.getElementById('player-frame').src = 'about:blank'; });

        // ----- error modal -----
        window.showError = function(elem) {
            document.getElementById('error-body').textContent = elem.textContent;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('errorModal')).show();
        };

        // ----- YouTube / image previews -----
        window.openYouTube = function(elem) {
            var input = elem.closest('.input-group').querySelector('input');
            var val = input ? input.value : '';
            if (!val) { return; }
            document.getElementById('player-frame').src = 'https://www.youtube.com/embed/' + encodeURIComponent(val);
            document.querySelector('#playerModal .modal-title').textContent = 'YouTube';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('playerModal')).show();
        };
        window.openImage = function(elem) {
            var img = elem.getAttribute('data-src');
            if (img) { window.open(img, '_blank'); }
        };

        <?php if ($rStream['type'] == 1): ?>
        // ----- stream source override + scan (live only) -----
        window.overrideSource = function(id, sourceIdx) {
            fetch('./api?action=stream&sub=force&stream_id=' + encodeURIComponent(id) + '&force_id=' + encodeURIComponent(sourceIdx), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function() { toast('Current source has been changed.', 'success'); })
                .catch(function() { toast(lang.error, 'error'); });
        };
        window.scanSources = function() {
            $('.stream_info').each(function() {
                var id = $(this).data('id');
                var cell = document.getElementById('stream_info_' + id);
                if (cell) { cell.innerHTML = '<span class="text-body-secondary">Probing source...</span>'; }
                fetch('./api?action=check_stream&stream=' + streamId + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.text(); })
                    .then(function(html) { if (cell) { cell.innerHTML = html; } })
                    .catch(function() {});
            });
        };
        <?php endif; ?>

        // ----- live connections modal -----
        window.viewLiveConnections = function(streamID, serverID) {
            if (typeof serverID === 'undefined') { serverID = -1; }
            $('#datatable-live').DataTable({
                destroy: true,
                ordering: true,
                paging: true,
                searching: true,
                processing: true,
                serverSide: true,
                searchDelay: 250,
                info: true,
                ajax: {
                    url: './table',
                    data: function(d) { d.id = 'live_connections'; d.stream_id = streamID; d.server_id = serverID; }
                },
                columnDefs: [
                    { className: 'text-center', targets: [1, 7, 8, 9, 10, 11] },
                    { visible: false, targets: [0, 3, 5, 6] }
                ]
            });
            bootstrap.Modal.getOrCreateInstance(document.getElementById('liveModal')).show();
        };

        // ----- small static tables -----
        $('#datatable-archive').DataTable({ ordering: false, searching: false, lengthChange: false, info: false, paging: false });
        $('#datatable-errors').DataTable({ ordering: true, searching: true, lengthChange: true, info: true, paging: true, order: [[0, 'desc']] });
        $('#datatable-sources').DataTable({ ordering: true, searching: false, lengthChange: false, info: false, paging: true });
        $('#datatable-adaptive').DataTable({ ordering: true, searching: false, lengthChange: false, info: false, paging: true, order: [[2, 'desc']] });
    })();
</script>
</body>

</html>
