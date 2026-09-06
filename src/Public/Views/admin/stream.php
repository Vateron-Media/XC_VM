<?php

/**
 * Live stream add / edit / import (Bootstrap 5) — core migration. Reached
 * full-page from the streams table ("Add" → stream, "Import" → stream?import) in
 * the new-UI shell, and as an iframe modal ("Edit" → stream?id=X&modal=1) in the
 * modal shell. Tabs: Details, Sources (the multi-URL list with reorder / provider
 * search / scan), Advanced (encode + auto-restart + adaptive-link + title-sync)
 * and Servers (the jstree load-balancer tree + on-demand / timeshift / thumbnail
 * servers). The EPG, custom-map and RTMP-push tabs are not rebuilt yet — their
 * saved values ride along as hidden inputs so an edit never wipes them. select2
 * tags for categories/bouquets; provider search is a Bootstrap modal (magnific
 * popup is not in the new-UI). Posts to post.php?action=stream via fetch; posts
 * xcModalSaved in the modal. Requires jstree (declared by the controller).
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;

global $db;

$rIsEdit   = isset($rStream['id']);
$rIsImport = RequestManager::has('import');
$rStreamCat = $rIsEdit ? (json_decode((string) $rStream['category_id'], true) ?: []) : [];
$rSources  = $rIsEdit ? (json_decode((string) $rStream['stream_source'], true) ?: ['']) : (RequestManager::has('url') ? [RequestManager::get('url')] : ['']);
if (!is_array($rSources) || !$rSources) {
    $rSources = [''];
}
$rAutoRestart = ($rIsEdit && !empty($rStream['auto_restart'])) ? (json_decode((string) $rStream['auto_restart'], true) ?: []) : [];
$rRestartDays = (array) ($rAutoRestart['days'] ?? []);
$rArg = static function (string $key, int $optId, string $default = '') use ($rStreamOptions, $rStreamArguments) {
    if (isset($rStreamOptions[$optId]['value'])) {
        return (string) $rStreamOptions[$optId]['value'];
    }
    return (string) ($rStreamArguments[$key]['argument_default_value'] ?? $default);
};
// Adaptive-link and title-sync need their currently-selected names for select2.
$rAdaptiveLink = $rIsEdit ? (json_decode((string) $rStream['adaptive_link'], true) ?: []) : [];
$rAdaptiveNames = [];
if ($rAdaptiveLink) {
    $db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rAdaptiveLink)) . ');');
    foreach ($db->get_rows() as $rRow) {
        $rAdaptiveNames[$rRow['id']] = '[' . $rRow['id'] . '] ' . $rRow['stream_display_name'];
    }
}
$rTitleSyncName = '';
if ($rIsEdit && !empty($rStream['title_sync'])) {
    [$rPid, $rSid] = array_map('intval', explode('_', (string) $rStream['title_sync']) + [0, 0]);
    $db->query('SELECT `stream_display_name` FROM `providers_streams` WHERE `provider_id` = ? AND `stream_id` = ?;', $rPid, $rSid);
    if ($db->num_rows() > 0) {
        $rTitleSyncName = '[' . $rSid . '] ' . $db->get_row()['stream_display_name'];
    }
}
$rTitle = $rIsEdit ? $rStream['stream_display_name'] : ($rIsImport ? 'Import Streams' : 'Add Stream');
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="streams" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= htmlspecialchars((string) $rTitle, ENT_QUOTES); ?></h4>
    </div>
<?php endif; ?>

<form id="stream-form" autocomplete="off" <?= $rIsImport ? ' enctype="multipart/form-data"' : ''; ?>>
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rStream['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
    <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
    <input type="hidden" name="external_push" id="external_push" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['external_push'], ENT_QUOTES) : ''; ?>">
    <input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="">
    <input type="hidden" name="category_create_list" id="category_create_list" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <?php if (!$rIsImport): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sources" role="tab"><i class="icon-base ti tabler-arrows-up-down me-1"></i><?= $language::get('sources'); ?></button></li>
                    <?php endif; ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('advanced'); ?></button></li>
                    <?php if (!$rIsImport): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-epg" role="tab"><i class="icon-base ti tabler-device-tv me-1"></i><?= $language::get('epg'); ?></button></li>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-map" role="tab"><i class="icon-base ti tabler-map me-1"></i><?= $language::get('map'); ?></button></li>
                        <?php if (empty($rMobile)): ?>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rtmp" role="tab"><i class="icon-base ti tabler-cloud-upload me-1"></i><?= $language::get('rtmp_push'); ?></button></li>
                        <?php endif; ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-capture" role="tab"><i class="icon-base ti tabler-device-cctv me-1"></i><?= $language::get('capture_server'); ?></button></li>
                    <?php endif; ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-server" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <?php if (!$rIsImport): ?>
                        <div class="mb-6">
                            <label class="form-label" for="stream_display_name"><?= $language::get('stream_name'); ?></label>
                            <input type="text" class="form-control" id="stream_display_name" name="stream_display_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['stream_display_name'], ENT_QUOTES) : (RequestManager::has('title') ? htmlspecialchars((string) RequestManager::get('title'), ENT_QUOTES) : ''); ?>">
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="stream_icon"><?= $language::get('stream_logo'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="stream_icon" name="stream_icon" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['stream_icon'], ENT_QUOTES) : (RequestManager::has('icon') ? htmlspecialchars((string) RequestManager::get('icon'), ENT_QUOTES) : ''); ?>">
                                <button type="button" class="btn btn-label-secondary" id="icon-preview"><i class="icon-base ti tabler-eye"></i></button>
                            </div>
                            <div class="mt-2"><img id="icon-img" src="" alt="" style="max-height:80px" hidden></div>
                        </div>
                    <?php else: ?>
                        <div class="mb-6">
                            <label class="form-label" for="m3u_file"><?= $language::get('m3u'); ?></label>
                            <input type="file" class="form-control" id="m3u_file" name="m3u_file">
                        </div>
                    <?php endif; ?>
                    <div class="mb-6">
                        <label class="form-label" for="category_id"><?= $language::get('categories'); ?></label>
                        <select name="category_id[]" id="category_id" class="form-select" multiple>
                            <?php foreach (CategoryService::getAllByType('live') as $rCategory): ?>
                                <option value="<?= (int) $rCategory['id']; ?>" <?= in_array((int) $rCategory['id'], array_map('intval', $rStreamCat), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="bouquets"><?= $language::get('bouquets'); ?></label>
                        <select name="bouquets[]" id="bouquets" class="form-select" multiple>
                            <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                <option value="<?= (int) $rBouquet['id']; ?>" <?= ($rIsEdit && in_array((int) $rStream['id'], array_map('intval', json_decode((string) $rBouquet['bouquet_channels'], true) ?: []), true)) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="notes"><?= $language::get('notes'); ?></label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rStream['notes'], ENT_QUOTES) : ''; ?></textarea>
                    </div>
                </div>

                <?php if (!$rIsImport): ?>
                    <div class="tab-pane fade" id="tab-sources" role="tabpanel">
                        <div id="sources-list">
                            <?php foreach ($rSources as $rSource): ?>
                                <div class="source-row mb-2">
                                    <div class="input-group">
                                        <button class="btn btn-label-secondary btn-src-up" type="button"><i class="icon-base ti tabler-chevron-up"></i></button>
                                        <button class="btn btn-label-secondary btn-src-down" type="button"><i class="icon-base ti tabler-chevron-down"></i></button>
                                        <input type="text" name="stream_source[]" class="form-control src-input" value="<?= htmlspecialchars((string) $rSource, ENT_QUOTES); ?>">
                                        <button class="btn btn-label-danger btn-src-remove" type="button"><i class="icon-base ti tabler-x"></i></button>
                                    </div>
                                    <div class="src-info small text-body-secondary mt-1"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-label-primary" id="add-source"><i class="icon-base ti tabler-plus me-1"></i><?= $language::get('add_row'); ?></button>
                            <?php if (empty($rMobile)): ?>
                                <button type="button" class="btn btn-label-primary" id="provider-streams"><?= $language::get('providers'); ?></button>
                                <button type="button" class="btn btn-label-info" id="scan-sources"><?= $language::get('scan_sources'); ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="gen_timestamps" name="gen_timestamps" value="1" <?= (!$rIsEdit || ($rStream['gen_timestamps'] ?? 0) == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="gen_timestamps">Generate PTS</label></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="read_native" name="read_native" value="1" <?= ($rIsEdit && ($rStream['read_native'] ?? 0) == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="read_native">Native Frames</label></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="stream_all" name="stream_all" value="1" <?= ($rIsEdit && ($rStream['stream_all'] ?? 0) == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="stream_all">Stream All Codecs</label></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="allow_record" name="allow_record" value="1" <?= (!$rIsEdit || ($rStream['allow_record'] ?? 0) == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="allow_record"><?= $language::get('allow_recording'); ?></label></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="direct_source" name="direct_source" value="1" <?= ($rIsEdit && ($rStream['direct_source'] ?? 0) == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="direct_source">Direct Source</label></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="direct_proxy" name="direct_proxy" value="1" <?= ($rIsEdit && ($rStream['direct_proxy'] ?? 0) == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="direct_proxy">Direct Stream</label></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="fps_restart" name="fps_restart" value="1" <?= ($rIsEdit && ($rStream['fps_restart'] ?? 0) == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="fps_restart">Restart on FPS Drop</label></div>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="fps_threshold">FPS Threshold %</label><input type="text" inputmode="numeric" class="form-control" id="fps_threshold" name="fps_threshold" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['fps_threshold'], ENT_QUOTES) : '90'; ?>"></div>
                    </div>
                    <div class="mb-6"><label class="form-label" for="custom_sid">Custom Channel SID</label><input type="text" class="form-control" id="custom_sid" name="custom_sid" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['custom_sid'], ENT_QUOTES) : ''; ?>"></div>
                    <div class="row mb-6">
                        <div class="col-md-6"><label class="form-label" for="probesize_ondemand">On Demand Probesize</label><input type="text" inputmode="numeric" class="form-control" id="probesize_ondemand" name="probesize_ondemand" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['probesize_ondemand'], ENT_QUOTES) : htmlspecialchars((string) ($rSettings['probesize_ondemand'] ?? ''), ENT_QUOTES); ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="delay_minutes">Minute Delay</label><input type="text" inputmode="numeric" class="form-control" id="delay_minutes" name="delay_minutes" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['delay_minutes'], ENT_QUOTES) : '0'; ?>"></div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6"><label class="form-label" for="user_agent"><?= $language::get('user_agent'); ?></label><input type="text" class="form-control" id="user_agent" name="user_agent" value="<?= htmlspecialchars($rArg('user_agent', 1), ENT_QUOTES); ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="http_proxy">HTTP Proxy</label><input type="text" class="form-control" id="http_proxy" name="http_proxy" value="<?= htmlspecialchars($rArg('proxy', 2), ENT_QUOTES); ?>"></div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6"><label class="form-label" for="cookie">Cookie</label><input type="text" class="form-control" id="cookie" name="cookie" value="<?= htmlspecialchars($rArg('cookie', 17), ENT_QUOTES); ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="headers">Headers</label><input type="text" class="form-control" id="headers" name="headers" value="<?= htmlspecialchars($rArg('headers', 19), ENT_QUOTES); ?>"></div>
                    </div>
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="skip_ffprobe" name="skip_ffprobe" value="1" <?= (isset($rStreamOptions[21]) && $rStreamOptions[21]['value'] == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="skip_ffprobe">Skip FFProbe</label></div>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="force_input_acodec">Force Input Audio Codec</label><input type="text" class="form-control" id="force_input_acodec" name="force_input_acodec" placeholder="<?= $language::get('eg_aac_ac3'); ?>" value="<?= isset($rStreamOptions[20]) ? htmlspecialchars((string) $rStreamOptions[20]['value'], ENT_QUOTES) : ''; ?>"></div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="transcode_profile_id">Transcoding Profile</label>
                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                            <option value="0" <?= ($rIsEdit && (int) $rStream['transcode_profile_id'] === 0) ? 'selected' : ''; ?>><?= $language::get('transcoding_disabled'); ?></option>
                            <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                <option value="<?= (int) $rProfile['profile_id']; ?>" <?= ($rIsEdit && (int) $rStream['transcode_profile_id'] === (int) $rProfile['profile_id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-8"><label class="form-label" for="days_to_restart"><?= $language::get('auto_restart_label'); ?></label>
                            <select id="days_to_restart" name="days_to_restart[]" class="form-select" multiple>
                                <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $rDay): ?>
                                    <option value="<?= $rDay; ?>" <?= in_array($rDay, $rRestartDays, true) ? 'selected' : ''; ?>><?= $rDay; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label" for="time_to_restart"><?= $language::get('time_to_restart'); ?></label><input id="time_to_restart" name="time_to_restart" type="text" class="form-control" value="<?= htmlspecialchars((string) ($rAutoRestart['at'] ?? '06:00'), ENT_QUOTES); ?>"></div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="adaptive_link">Adaptive Link</label>
                        <select name="adaptive_link[]" id="adaptive_link" class="form-select" multiple>
                            <?php foreach ($rAdaptiveLink as $rAid): ?>
                                <option value="<?= (int) $rAid; ?>" selected><?= htmlspecialchars((string) ($rAdaptiveNames[$rAid] ?? $rAid), ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="title_sync">Sync Title</label>
                        <div class="input-group">
                            <select id="title_sync" name="title_sync" class="form-select">
                                <?php if ($rTitleSyncName !== ''): ?>
                                    <option value="<?= htmlspecialchars((string) $rStream['title_sync'], ENT_QUOTES); ?>" selected><?= htmlspecialchars((string) $rTitleSyncName, ENT_QUOTES); ?></option>
                                <?php endif; ?>
                            </select>
                            <button type="button" class="btn btn-label-warning" id="clear-title"><?= $language::get('clear'); ?></button>
                        </div>
                    </div>
                </div>

                <?php if (!$rIsImport): ?>
                    <?php
                    $rSelEpgId   = $rIsEdit ? (int) $rStream['epg_id'] : 0;
                    $rSelChannel = $rIsEdit ? (string) $rStream['channel_id'] : '';
                    $rSelLang    = $rIsEdit ? (string) $rStream['epg_lang'] : '';
                    $rEpgData    = ($rSelEpgId && isset($rEPGSources[$rSelEpgId])) ? (json_decode((string) $rEPGSources[$rSelEpgId]['data'], true) ?: []) : [];
                    ?>
                    <div class="tab-pane fade" id="tab-epg" role="tabpanel">
                        <ul class="nav nav-pills mb-4" role="tablist">
                            <li class="nav-item"><button type="button" class="nav-link <?= $rIsEdit ? '' : 'active'; ?>" data-bs-toggle="tab" data-bs-target="#epg-quick" role="tab"><?= $language::get('search_epg'); ?></button></li>
                            <li class="nav-item"><button type="button" class="nav-link <?= $rIsEdit ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#epg-xmltv" role="tab"><?= $language::get('xmltv_epg'); ?></button></li>
                        </ul>
                        <div class="tab-content p-0">
                            <div class="tab-pane fade <?= $rIsEdit ? '' : 'show active'; ?>" id="epg-quick" role="tabpanel">
                                <div>
                                    <label class="form-label" for="quick_search"><?= $language::get('search_epg'); ?></label>
                                    <select id="quick_search" class="form-select"></select>
                                </div>
                            </div>
                            <div class="tab-pane fade <?= $rIsEdit ? 'show active' : ''; ?>" id="epg-xmltv" role="tabpanel">
                                <div class="mb-6">
                                    <label class="form-label" for="epg_id"><?= $language::get('epg_source'); ?></label>
                                    <select name="epg_id" id="epg_id" class="form-select">
                                        <option value="0" <?= $rSelEpgId === 0 ? 'selected' : ''; ?>><?= $language::get('no_epg'); ?></option>
                                        <?php foreach ($rEPGSources as $rEPG): ?>
                                            <option value="<?= (int) $rEPG['id']; ?>" <?= $rSelEpgId === (int) $rEPG['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rEPG['epg_name'], ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-6">
                                    <label class="form-label" for="channel_id"><?= $language::get('epg_channel_id'); ?></label>
                                    <select name="channel_id" id="channel_id" class="form-select">
                                        <?php foreach ($rEpgData as $rChId => $rEpgChannel): ?>
                                            <option value="<?= htmlspecialchars((string) $rChId, ENT_QUOTES); ?>" <?= ((string) $rChId === $rSelChannel) ? 'selected' : ''; ?>><?= htmlspecialchars((string) ($rEpgChannel['display_name'] ?? $rChId), ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row mb-6">
                                    <div class="col-md-8">
                                        <label class="form-label" for="epg_lang"><?= $language::get('epg_language'); ?></label>
                                        <select name="epg_lang" id="epg_lang" class="form-select">
                                            <?php foreach ((array) ($rEpgData[$rSelChannel]['langs'] ?? []) as $rLang): ?>
                                                <option value="<?= htmlspecialchars((string) $rLang, ENT_QUOTES); ?>" <?= ((string) $rLang === $rSelLang) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rLang, ENT_QUOTES); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="epg_offset"><?= $language::get('minute_offset'); ?></label>
                                        <input type="text" inputmode="numeric" class="form-control" id="epg_offset" name="epg_offset" value="<?= $rIsEdit ? (int) $rStream['epg_offset'] : 0; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-map" role="tabpanel">
                        <div class="alert alert-info" role="alert"><?= $language::get('custom_map_info'); ?></div>
                        <div>
                            <label class="form-label" for="custom_map"><?= $language::get('custom_map'); ?></label>
                            <input type="text" class="form-control" id="custom_map" name="custom_map" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['custom_map'], ENT_QUOTES) : ''; ?>">
                        </div>
                    </div>

                    <?php if (empty($rMobile)): ?>
                        <div class="tab-pane fade" id="tab-rtmp" role="tabpanel">
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" id="rtmp_output" name="rtmp_output" value="1" <?= ($rIsEdit && (int) $rStream['rtmp_output'] === 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="rtmp_output"><?= $language::get('output_rtmp'); ?></label>
                            </div>
                            <div class="alert alert-info" role="alert"><?= $language::get('rtmp_push_info'); ?></div>
                            <div class="card-datatable table-responsive mb-3">
                                <table id="datatable-rtmp" class="table">
                                    <thead>
                                        <tr>
                                            <th><?= $language::get('push_from'); ?></th>
                                            <th><?= $language::get('rtmp_url'); ?></th>
                                            <th class="text-center"><?= $language::get('actions'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $rRTMPPush = $rIsEdit ? (json_decode((string) $rStream['external_push'], true) ?: []) : [];
                                        if (!$rRTMPPush) {
                                            $rRTMPPush = ['' => ['']];
                                        }
                                        foreach ($rRTMPPush as $rPushServerID => $rPushSources):
                                            foreach ((array) $rPushSources as $rPushSource): ?>
                                                <tr class="rtmp-row">
                                                    <td class="rtmp-server">
                                                        <select class="form-select rtmp-server-select">
                                                            <?php foreach ($rServers as $rServer): ?>
                                                                <option value="<?= (int) $rServer['id']; ?>" <?= ((string) $rPushServerID === (string) $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td><input type="text" class="form-control rtmp-url-input" value="<?= htmlspecialchars((string) $rPushSource, ENT_QUOTES); ?>"></td>
                                                    <td class="text-center"><button type="button" class="btn btn-label-danger btn-rtmp-remove"><i class="icon-base ti tabler-x"></i></button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-label-info" id="add-rtmp"><i class="icon-base ti tabler-plus me-1"></i><?= $language::get('add_rtmp_url'); ?></button>
                        </div>
                    <?php endif; ?>

                    <div class="tab-pane fade" id="tab-capture" role="tabpanel">
                        <div>
                            <label class="form-label" for="capture_server_id"><?= $language::get('capture_server'); ?></label>
                            <select name="capture_server_id" id="capture_server_id" class="form-select">
                                <option value="0" <?= (!$rIsEdit || (int) ($rStream['capture_server_id'] ?? 0) === 0) ? 'selected' : ''; ?>><?= $language::get('disabled'); ?></option>
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>" <?= ($rIsEdit && (int) ($rStream['capture_server_id'] ?? 0) === (int) $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="tab-pane fade" id="tab-server" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label"><?= $language::get('server_tree'); ?></label>
                        <div id="server_tree" class="border rounded p-2"></div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6"><label class="form-label" for="on_demand"><?= $language::get('on_demand_servers'); ?></label>
                            <select name="on_demand[]" id="on_demand" class="form-select" multiple>
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>" <?= in_array((int) $rServer['id'], array_map('intval', $rOnDemand), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="llod">Low Latency On-Demand</label>
                            <select name="llod" id="llod" class="form-select">
                                <?php foreach (['Disabled', 'LLOD v2 - FFMPEG', 'LLOD v3 - PHP'] as $rVal => $rText): ?>
                                    <option value="<?= $rVal; ?>" <?= ($rIsEdit && (int) $rStream['llod'] === $rVal) ? 'selected' : ''; ?>><?= $rText; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6"><label class="form-label" for="tv_archive_server_id"><?= $language::get('timeshift_server'); ?></label>
                            <select name="tv_archive_server_id" id="tv_archive_server_id" class="form-select">
                                <option value="0"><?= $language::get('disabled'); ?></option>
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>" <?= ($rIsEdit && (int) $rStream['tv_archive_server_id'] === (int) $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="tv_archive_duration"><?= $language::get('timeshift_days'); ?></label><input type="text" inputmode="numeric" class="form-control" id="tv_archive_duration" name="tv_archive_duration" value="<?= $rIsEdit ? htmlspecialchars((string) $rStream['tv_archive_duration'], ENT_QUOTES) : '0'; ?>"></div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6"><label class="form-label" for="vframes_server_id"><?= $language::get('thumbnail_server'); ?></label>
                            <select name="vframes_server_id" id="vframes_server_id" class="form-select">
                                <option value="0"><?= $language::get('disabled'); ?></option>
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>" <?= ($rIsEdit && (int) $rStream['vframes_server_id'] === (int) $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="restart_on_edit" name="restart_on_edit" value="1">
                        <label class="form-check-label" for="restart_on_edit"><?= $rIsEdit ? 'Restart on Edit' : 'Start Stream Now'; ?></label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="stream-submit"><?= $language::get('save') ?: 'Save'; ?></button>
    </div>
</form>

<!-- Provider search modal -->
<div class="modal fade" id="providerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('provider_streams'); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="card-datatable table-responsive">
                    <table id="datatable-provider-streams" class="table">
                        <thead>
                            <tr>
                                <th class="text-center"><?= $language::get('icon'); ?></th>
                                <th><?= $language::get('stream_name'); ?></th>
                                <th><?= $language::get('provider'); ?></th>
                                <th class="text-center"><?= $language::get('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- EPG picon prompt modal -->
<div class="modal fade" id="epgPicon" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('use_icon'); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p><?= $language::get('epg_picon_prompt'); ?></p>
                <img id="epg-picon" src="" alt="" style="max-height:96px">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="epg_picon_save"><?= $language::get('use_icon'); ?></button>
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
        if (!$) {
            return;
        }
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var isImport = <?= $rIsImport ? 'true' : 'false'; ?>;

        function esc(s) {
            return $('<div>').text(s == null ? '' : s).html();
        }
        // Render the legacy probe HTML table (mdi icons, not in the new-UI) as clean
        // Bootstrap badges with Tabler icons: dims / video codec / audio codec / fps
        // / container compatibility.
        function probeBadge(color, icon, text) {
            return '<span class="badge bg-label-' + color + ' me-1 mb-1"><i class="icon-base ti ' + icon + ' me-1"></i>' + esc(text) + '</span>';
        }

        function renderProbe(html) {
            var rows = $('<div>').html(html).find('tr');
            if (rows.length < 2) {
                return '<span class="badge bg-label-danger"><i class="icon-base ti tabler-alert-triangle me-1"></i>Probe failed</span>';
            }
            var v = rows.eq(1).find('td').map(function() {
                return $(this).text().replace(/ /g, ' ').trim();
            }).get();
            var contIcon = rows.eq(0).find('td').last().find('i').attr('class') || '';
            var mpegts = contIcon.indexOf('check') !== -1;
            var out = '';
            if (v[0]) {
                out += probeBadge('secondary', 'tabler-aspect-ratio', v[0]);
            }
            if (v[1]) {
                out += probeBadge('primary', 'tabler-video', v[1]);
            }
            if (v[2]) {
                out += probeBadge('info', 'tabler-volume', v[2]);
            }
            if (v[3]) {
                out += probeBadge('secondary', 'tabler-gauge', v[3]);
            }
            out += '<span class="badge ' + (mpegts ? 'bg-label-success' : 'bg-label-warning') + ' me-1 mb-1"><i class="icon-base ti ' + (mpegts ? 'tabler-circle-check' : 'tabler-alert-triangle') + ' me-1"></i>' + (mpegts ? 'MPEG-TS' : 'Not MPEG-TS') + '</span>';
            return '<div class="d-flex flex-wrap">' + out + '</div>';
        }

        function collectNew(sel) {
            var vals = $(sel).val() || [],
                nw = [];
            vals.forEach(function(v) {
                if (!/^\d+$/.test(v)) {
                    nw.push(v);
                }
            });
            return JSON.stringify(nw);
        }

        // ---- select2 ----
        $('#category_id, #bouquets').select2({
            width: '100%',
            tags: true,
            dropdownParent: $('#tab-details')
        });
        $('#transcode_profile_id, #days_to_restart, #on_demand, #llod, #tv_archive_server_id, #vframes_server_id').select2({
            width: '100%',
            dropdownParent: $('#stream-form')
        });

        function select2Ajax(sel, action, ph) {
            $(sel).select2({
                width: '100%',
                placeholder: ph,
                allowClear: true,
                dropdownParent: $('#tab-advanced'),
                ajax: {
                    url: './api',
                    dataType: 'json',
                    cache: true,
                    data: function(params) {
                        return {
                            search: params.term,
                            action: action,
                            page: params.page
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.items,
                            pagination: {
                                more: (params.page * 100) < data.total_count
                            }
                        };
                    }
                }
            });
        }
        select2Ajax('#adaptive_link', 'adaptivelist', 'Search for a stream...');
        select2Ajax('#title_sync', 'titlesync', 'Search for a stream...');
        document.getElementById('clear-title').addEventListener('click', function() {
            $('#title_sync').val('').trigger('change');
        });

        // ---- EPG tab: source → channel → language cascade ----
        var rEPG = <?= json_encode($rEPGJS); ?>;
        if (document.getElementById('epg_id')) {
            $('#epg_id, #channel_id, #epg_lang').select2({
                width: '100%',
                dropdownParent: $('#epg-xmltv')
            });

            function selectEPGSource() {
                var epgId = $('#epg_id').val(),
                    ch = $('#channel_id').empty();
                $('#epg_lang').empty();
                if (rEPG[epgId]) {
                    $.each(rEPG[epgId], function(key, data) {
                        ch.append(new Option(data.display_name, key, false, false));
                    });
                }
                ch.trigger('change');
            }

            function selectEPGID() {
                var epgId = $('#epg_id').val(),
                    chId = $('#channel_id').val(),
                    lang = $('#epg_lang').empty();
                if (rEPG[epgId] && rEPG[epgId][chId]) {
                    $.each(rEPG[epgId][chId].langs, function(i, data) {
                        lang.append(new Option(data, data, false, false));
                    });
                }
                lang.trigger('change');
            }
            $('#epg_id').on('change', selectEPGSource);
            $('#channel_id').on('change', selectEPGID);

            // Quick Search — ajax select2 over ./api?action=epglist, auto-fills the XMLTV fields.
            $('#quick_search').select2({
                width: '100%',
                placeholder: <?= json_encode($language::get('search_epg')); ?>,
                allowClear: true,
                dropdownParent: $('#epg-quick'),
                ajax: {
                    url: './api',
                    dataType: 'json',
                    cache: true,
                    data: function(params) {
                        return {
                            search: params.term,
                            action: 'epglist',
                            page: params.page
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.items,
                            pagination: {
                                more: (params.page * 100) < data.total_count
                            }
                        };
                    }
                }
            });
            $('#quick_search').on('change', function() {
                if (!$(this).val()) {
                    return;
                }
                var d = ($('#quick_search').select2('data') || [])[0];
                if (d && d.type == 0) {
                    $('#epg_id').val(d.epg_id).trigger('change');
                    $('#channel_id').val(d.id).trigger('change');
                    $('#epg_lang').val(d.lang).trigger('change');
                    var xmltvBtn = document.querySelector('[data-bs-target="#epg-xmltv"]');
                    if (xmltvBtn && window.bootstrap) {
                        bootstrap.Tab.getOrCreateInstance(xmltvBtn).show();
                    }
                    var nm = document.getElementById('stream_display_name');
                    if (nm && nm.value.length === 0 && d.text) {
                        nm.value = d.text;
                    }
                    if (d.icon) {
                        window.offerEpgPicon(d.icon);
                    }
                }
                $('#quick_search').val('').trigger('change');
            });
        }

        // ---- EPG picon prompt: offer to use the EPG channel icon as the stream logo ----
        window.offerEpgPicon = function(url) {
            var iconField = document.getElementById('stream_icon'),
                modalEl = document.getElementById('epgPicon');
            if (!iconField || !modalEl || !url || !window.bootstrap) {
                return;
            }
            var img = document.getElementById('epg-picon');
            if (img) {
                img.src = url;
            }
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        };
        var epgPiconSave = document.getElementById('epg_picon_save');
        if (epgPiconSave) {
            epgPiconSave.addEventListener('click', function() {
                var img = document.getElementById('epg-picon'),
                    iconField = document.getElementById('stream_icon');
                if (img && iconField) {
                    iconField.value = img.getAttribute('src') || '';
                }
                var m = bootstrap.Modal.getInstance(document.getElementById('epgPicon'));
                if (m) {
                    m.hide();
                }
            });
        }

        // ---- Capture tab ----
        $('#capture_server_id').select2({
            width: '100%',
            dropdownParent: $('#tab-capture')
        });

        // ---- RTMP push tab: add/remove target rows, collected into #external_push on submit ----
        if (document.getElementById('datatable-rtmp')) {
            var rServerOptions = <?= json_encode(array_map(static fn(array $rSrv): array => ['id' => (int) $rSrv['id'], 'name' => (string) $rSrv['server_name']], $rServers)); ?>;
            var rtmpBody = document.querySelector('#datatable-rtmp tbody');

            function rtmpServerSelectHtml() {
                var h = '<select class="form-select rtmp-server-select">';
                rServerOptions.forEach(function(s) {
                    h += '<option value="' + s.id + '">' + esc(s.name) + '</option>';
                });
                return h + '</select>';
            }
            $('#datatable-rtmp .rtmp-server-select').select2({
                width: '100%',
                dropdownParent: $('#tab-rtmp')
            });
            var addRtmpBtn = document.getElementById('add-rtmp');
            if (addRtmpBtn) {
                addRtmpBtn.addEventListener('click', function() {
                    var tr = document.createElement('tr');
                    tr.className = 'rtmp-row';
                    tr.innerHTML = '<td class="rtmp-server">' + rtmpServerSelectHtml() + '</td>' +
                        '<td><input type="text" class="form-control rtmp-url-input" value=""></td>' +
                        '<td class="text-center"><button type="button" class="btn btn-label-danger btn-rtmp-remove"><i class="icon-base ti tabler-x"></i></button></td>';
                    rtmpBody.appendChild(tr);
                    $(tr).find('.rtmp-server-select').select2({
                        width: '100%',
                        dropdownParent: $('#tab-rtmp')
                    });
                });
            }
            if (rtmpBody) {
                rtmpBody.addEventListener('click', function(e) {
                    var btn = e.target.closest('.btn-rtmp-remove');
                    if (!btn) {
                        return;
                    }
                    var row = btn.closest('.rtmp-row');
                    if (rtmpBody.querySelectorAll('.rtmp-row').length > 1) {
                        row.remove();
                    } else {
                        $(row).find('.rtmp-server-select').val(rServerOptions.length ? rServerOptions[0].id : '').trigger('change');
                        row.querySelector('.rtmp-url-input').value = '';
                    }
                });
            }
        }

        if (window.flatpickr) {
            flatpickr('#time_to_restart', {
                enableTime: true,
                noCalendar: true,
                dateFormat: 'H:i',
                time_24hr: true
            });
        }

        // ---- logo preview ----
        var ip = document.getElementById('icon-preview');
        if (ip) {
            ip.addEventListener('click', function() {
                var v = document.getElementById('stream_icon').value.trim(),
                    img = document.getElementById('icon-img');
                if (!v) {
                    img.hidden = true;
                    return;
                }
                img.src = 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(v);
                img.hidden = false;
            });
        }

        // ---- sources list ----
        var list = document.getElementById('sources-list');
        if (list) {
            list.addEventListener('click', function(e) {
                var btn = e.target.closest('button');
                if (!btn) {
                    return;
                }
                var row = btn.closest('.source-row');
                if (btn.classList.contains('btn-src-up') && row.previousElementSibling) {
                    row.parentNode.insertBefore(row, row.previousElementSibling);
                } else if (btn.classList.contains('btn-src-down') && row.nextElementSibling) {
                    row.parentNode.insertBefore(row.nextElementSibling, row);
                } else if (btn.classList.contains('btn-src-remove')) {
                    if (list.querySelectorAll('.source-row').length > 1) {
                        row.remove();
                    } else {
                        row.querySelector('.src-input').value = '';
                        row.querySelector('.src-info').innerHTML = '';
                    }
                }
            });
        }
        window.addStream = function(url) {
            var rows = list.querySelectorAll('.source-row'),
                last = rows[rows.length - 1];
            if (last.querySelector('.src-input').value.length > 0) {
                var clone = rows[0].cloneNode(true);
                clone.querySelector('.src-input').value = '';
                clone.querySelector('.src-info').innerHTML = '';
                list.appendChild(clone);
                last = clone;
            }
            if (url) {
                last.querySelector('.src-input').value = url;
                var m = bootstrap.Modal.getInstance(document.getElementById('providerModal'));
                if (m) {
                    m.hide();
                }
            }
        };
        var addBtn = document.getElementById('add-source');
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                window.addStream();
            });
        }
        var scanBtn = document.getElementById('scan-sources');
        if (scanBtn) {
            scanBtn.addEventListener('click', function() {
                var onlineSrv = $('#server_tree').jstree(true).get_json('source', {
                    flat: true
                });
                var server = (onlineSrv[1] !== undefined) ? onlineSrv[1].id : '';
                list.querySelectorAll('.source-row').forEach(function(row) {
                    var url = row.querySelector('.src-input').value;
                    if (!url) {
                        return;
                    }
                    var info = row.querySelector('.src-info');
                    info.innerHTML = '<span class="badge bg-label-secondary"><span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;vertical-align:-1px"></span>Probing…</span>';
                    $.get('./api?action=probe_stream&url=' + encodeURIComponent(url) + '&user_agent=' + encodeURIComponent($('#user_agent').val()) + '&proxy=' + encodeURIComponent($('#http_proxy').val()) + '&cookies=' + encodeURIComponent($('#cookie').val()) + '&headers=' + encodeURIComponent($('#headers').val()) + '&server=' + server, function(data) {
                        info.innerHTML = renderProbe(data);
                    });
                });
            });
        }

        // ---- provider search ----
        var provBtn = document.getElementById('provider-streams');
        if (provBtn) {
            var provTable = $('#datatable-provider-streams').DataTable({
                processing: true,
                serverSide: true,
                searchDelay: 250,
                responsive: false,
                order: [
                    [0, 'asc']
                ],
                pageLength: <?= (int) ($rSettings['default_entries'] ?? 10) ?: 10; ?>,
                ajax: {
                    url: './table',
                    data: function(d) {
                        d.id = 'provider_streams';
                        d.type = 'live';
                    }
                },
                columnDefs: [{
                    className: 'dt-center',
                    targets: [0, 3]
                }]
            });
            provBtn.addEventListener('click', function() {
                provTable.search(document.getElementById('stream_display_name') ? document.getElementById('stream_display_name').value : '').draw();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('providerModal')).show();
            });
        }

        // ---- jstree server tree + evaluateServers ----
        function evaluateServers() {
            var v = $('#vframes_server_id').val(),
                t = $('#tv_archive_server_id').val(),
                o = $('#on_demand').val();
            $('#on_demand').empty();
            $('#vframes_server_id').empty().append(new Option('Disabled', 0));
            $('#tv_archive_server_id').empty().append(new Option('Disabled', 0));
            $($('#server_tree').jstree(true).get_json('source', {
                flat: true
            })).each(function(i, val) {
                if (val.parent !== '#') {
                    $('#vframes_server_id').append(new Option(val.text, val.id));
                    $('#tv_archive_server_id').append(new Option(val.text, val.id));
                    $('#on_demand').append(new Option(val.text, val.id));
                }
            });
            $('#vframes_server_id').val(v || 0).trigger('change');
            $('#tv_archive_server_id').val(t || 0).trigger('change');
            $('#on_demand').val(o).trigger('change');
        }
        $('#server_tree')
            .on('redraw.jstree', function() {
                evaluateServers();
            })
            .on('select_node.jstree', function(e, data) {
                if (data.node.id === 'source' || data.node.id === 'offline') {
                    return;
                }
                var to = (data.node.parent === 'offline') ? 'source' : 'offline';
                $('#server_tree').jstree('move_node', data.node.id, to, to === 'source' ? 'last' : 'first');
            })
            .jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') {
                                return false;
                            }
                            if (parent.id === '#') {
                                return false;
                            }
                            if (parent.id > 0 && $('#direct_proxy').is(':checked')) {
                                return false;
                            }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree); ?>
                },
                plugins: ['dnd']
            });

        // ---- direct source / proxy enable-disable ----
        var dsFields = ['llod', 'fps_restart', 'fps_threshold', 'adaptive_link', 'custom_sid', 'read_native', 'gen_timestamps', 'stream_all', 'allow_record', 'rtmp_output', 'delay_minutes', 'custom_map', 'probesize_ondemand', 'transcode_profile_id', 'days_to_restart', 'time_to_restart', 'on_demand', 'tv_archive_duration', 'tv_archive_server_id', 'vframes_server_id', 'restart_on_edit'];

        function setDis(id, off) {
            var el = document.getElementById(id);
            if (el) {
                el.disabled = off;
                if ($(el).hasClass('select2-hidden-accessible')) {
                    $(el).prop('disabled', off).trigger('change.select2');
                }
            }
        }

        function evaluateDirectSource() {
            var ds = document.getElementById('direct_source').checked,
                dp = document.getElementById('direct_proxy').checked;
            dsFields.forEach(function(id) {
                setDis(id, ds);
            });
            setDis('direct_proxy', !ds);
            ['user_agent', 'http_proxy', 'cookie', 'headers'].forEach(function(id) {
                setDis(id, !(dp || !ds));
            });
        }
        document.getElementById('direct_source').addEventListener('change', evaluateDirectSource);
        document.getElementById('direct_proxy').addEventListener('change', evaluateDirectSource);
        evaluateDirectSource();

        // ---- submit ----
        document.getElementById('stream-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!isImport && !document.getElementById('stream_display_name').value.trim()) {
                alert(errText);
                return;
            }
            if (isImport && !document.getElementById('m3u_file').value) {
                alert(errText);
                return;
            }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', {
                flat: true
            }));
            document.getElementById('category_create_list').value = collectNew('#category_id');
            document.getElementById('bouquet_create_list').value = collectNew('#bouquets');
            var epExt = document.getElementById('external_push');
            if (epExt && document.getElementById('datatable-rtmp')) {
                var rtmpPush = {};
                document.querySelectorAll('#datatable-rtmp tbody .rtmp-row').forEach(function(row) {
                    var selEl = row.querySelector('.rtmp-server-select');
                    var sid = selEl ? $(selEl).val() : '';
                    var url = row.querySelector('.rtmp-url-input').value;
                    if (sid > 0 && url.length > 0) {
                        if (!rtmpPush[sid]) {
                            rtmpPush[sid] = [];
                        }
                        rtmpPush[sid].push(url);
                    }
                });
                epExt.value = JSON.stringify(rtmpPush);
            }
            document.querySelectorAll('#stream-form :disabled').forEach(function(el) {
                el.disabled = false;
            });
            var btn = document.getElementById('stream-submit');
            btn.disabled = true;
            fetch('post.php?action=stream', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var dt;
                    try {
                        dt = JSON.parse(txt);
                    } catch (err) {
                        dt = {
                            result: false
                        };
                    }
                    if (dt && dt.result !== false) {
                        if (window.parent !== window) {
                            window.parent.postMessage('xcModalSaved', '*');
                        } else {
                            window.location.href = dt.location || 'streams';
                        }
                        return;
                    }
                    btn.disabled = false;
                    evaluateDirectSource();
                    alert(errText);
                })
                .catch(function() {
                    btn.disabled = false;
                    evaluateDirectSource();
                    alert(errText);
                });
        });
    })();
</script>
</body>

</html>