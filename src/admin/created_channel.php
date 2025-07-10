<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rCategories = getCategories('live');
$rTranscodeProfiles = getTranscodeProfiles();

if (!isset(CoreUtilities::$rRequest['id'])) {
    $rChannel = null;
} else {
    $rChannel = getStream(CoreUtilities::$rRequest['id']);

    if (!$rChannel || $rChannel['type'] != 3) {
        goHome();
    }
}

$rOnDemand = [];
$rServerTree = [
    [
        'id' => 'source',
        'parent' => '#',
        'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>",
        'icon' => 'mdi mdi-play',
        'state' => ['opened' => true]
    ],
    [
        'id' => 'offline',
        'parent' => '#',
        'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>",
        'icon' => 'mdi mdi-stop',
        'state' => ['opened' => true]
    ]
];

if (isset($rChannel)) {
    $rProperties = json_decode($rChannel['movie_properties'], true);

    if (!$rProperties) {
        $rProperties = ['type' => $rChannel['series_no'] > 0 ? 0 : 1];
    }

    $rChannelSys = getStreamSys(CoreUtilities::$rRequest['id']);

    foreach ($rServers as $rServer) {
        if (isset($rChannelSys[intval($rServer['id'])])) {
            $rParent = $rChannelSys[intval($rServer['id'])]['parent_id'] != 0
                ? intval($rChannelSys[intval($rServer['id'])]['parent_id'])
                : (!$rChannelSys[intval($rServer['id'])]['on_demand'] ? 'source' : null);
        } else {
            $rParent = 'offline';
        }

        if ($rParent !== null) {
            $rServerTree[] = [
                'id' => $rServer['id'],
                'parent' => $rParent,
                'text' => $rServer['server_name'],
                'icon' => 'mdi mdi-server-network',
                'state' => ['opened' => true]
            ];
        }
    }
} else {
    foreach ($rServers as $rServer) {
        $rServerTree[] = [
            'id' => $rServer['id'],
            'parent' => 'offline',
            'text' => $rServer['server_name'],
            'icon' => 'mdi mdi-server-network',
            'state' => ['opened' => true]
        ];
    }
}

$_TITLE = 'Created Channel';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php echo empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ? '' : ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo isset($rChannel) ? $rChannel['stream_display_name'] : 'Create Channel'; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php
                if (isset($rChannel)) {
                    $rEncodeErrors = getEncodeErrors($rChannel['id']);

                    foreach ($rEncodeErrors as $rServerID => $rEncodeError) {
                ?>
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            <strong>Error on Server - <?php echo $rServers[$rServerID]['server_name']; ?></strong><br />
                            <?php echo str_replace("\n", '<br/>', $rEncodeError); ?>
                        </div>
                <?php
                    }
                }
                ?>
                <div class="card">
                    <div class="card-body">
                        <form action="./created_channel<?php echo isset(CoreUtilities::$rRequest['id']) ? '?id=' . intval(CoreUtilities::$rRequest['id']) : ''; ?>" method="POST" id="stream_form" data-parsley-validate="">
                            <?php if (isset($rChannel)): ?>
                                <input type="hidden" name="edit" value="<?php echo $rChannel['id']; ?>" />
                            <?php endif; ?>
                            <input type="hidden" name="video_files" id="video_files" value="" />
                            <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                            <input type="hidden" name="external_push" id="external_push" value="" />
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
                                    <li class="nav-item" id="selection_nav">
                                        <a href="#selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-movie mr-1"></i>
                                            <span class="d-none d-sm-inline">Selection</span>
                                        </a>
                                    </li>
                                    <li class="nav-item" id="review_nav">
                                        <a href="#review" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-marker mr-1"></i>
                                            <span class="d-none d-sm-inline">Review</span>
                                        </a>
                                    </li>
                                    <li class="nav-item" id="videos_nav">
                                        <a href="#videos" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-movie mr-1"></i>
                                            <span class="d-none d-sm-inline">Videos</span>
                                        </a>
                                    </li>
                                    <?php if (!$rMobile): ?>
                                        <li class="nav-item">
                                            <a href="#rtmp-push" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-upload-network-outline mr-1"></i>
                                                <span class="d-none d-sm-inline">RTMP Push</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline">Servers</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="stream_display_name">Channel Name</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="stream_display_name" name="stream_display_name" value="<?php echo isset($rChannel) ? htmlspecialchars($rChannel['stream_display_name']) : ''; ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="stream_icon">Channel Logo</label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" class="form-control" id="stream_icon" name="stream_icon" value="<?php echo isset($rChannel) ? htmlspecialchars($rChannel['stream_icon']) : ''; ?>">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" onclick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="category_id">Categories</label>
                                                    <div class="col-md-8">
                                                        <select name="category_id[]" id="category_id" class="form-control select2 select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getCategories('live') as $rCategory): ?>
                                                                <option <?php if (isset($rChannel) && in_array(intval($rCategory['id']), json_decode($rChannel['category_id'], true))): ?>selected<?php endif; ?> value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div id="category_create" class="alert bg-dark text-white border-0 mt-2 mb-0" style="display: none;">
                                                            <strong>New Categories:</strong> <span id="category_new"></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="bouquets">Bouquets</label>
                                                    <div class="col-md-8">
                                                        <select name="bouquets[]" id="bouquets" class="form-control select2-multiple select2" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet): ?>
                                                                <option <?php if (isset($rChannel) && in_array($rChannel['id'], json_decode($rBouquet['bouquet_channels'], true))): ?>selected<?php endif; ?> value="<?php echo $rBouquet['id']; ?>"><?php echo $rBouquet['bouquet_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div id="bouquet_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">
                                                            <strong>New Bouquets:</strong> <span id="bouquet_new"></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="channel_type">Selection Type <i title="You can create a channel by either syncing it to an existing series, selecting VOD you already have on your servers or by individually selecting files." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="channel_type" id="channel_type" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array('Series', 'File Browser', 'VOD Selection') as $rID => $rType): ?>
                                                                <option <?php if (isset($rChannel) && $rProperties['type'] == $rID): ?>selected<?php endif; ?> value="<?php echo $rID; ?>"><?php echo $rType; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4" id="series_nav">
                                                    <label class="col-md-4 col-form-label" for="series_no">24/7 Series <i title="Select a series to sync with." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="series_no" id="series_no" class="form-control select2" data-toggle="select2">
                                                            <option value="0">Select a series...</option>
                                                            <?php foreach (getSeries() as $rSeries): ?>
                                                                <option <?php if (isset($rChannel) && intval($rChannel['series_no']) == intval($rSeries['id'])): ?>selected<?php endif; ?> value="<?php echo $rSeries['id']; ?>"><?php echo $rSeries['title']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-12" style="margin-bottom:10px; display:none;" id="warning">
                                                        <div class="alert alert-warning" role="alert">
                                                            Not all videos are supported as-is when streaming live, this could mean no video or audio displays. If this happens, you will need to transcode.<br />
                                                            Symlink's will only be created on the server the file originates from, if you're streaming to another server it will be downloaded normally.
                                                        </div>
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="transcode_profile_id">Transcoding Profile <i title="Transcode videos using a profile, copy them or symlink them directly." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-control select2" data-toggle="select2">
                                                            <option <?php if (!isset($rChannel) || (isset($rChannel) && intval($rChannel['transcode_profile_id']) == 0)) echo 'selected'; ?> value="0">Quick Transcode - Copy Codecs</option>
                                                            <option <?php if (isset($rChannel) && intval($rChannel['transcode_profile_id']) == -1) echo 'selected'; ?> value="-1">Don't Transcode - Symlink Files</option>
                                                            <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                                                <option <?php if (isset($rChannel) && intval($rChannel['transcode_profile_id']) == intval($rProfile['profile_id'])) echo 'selected'; ?> value="<?php echo $rProfile['profile_id']; ?>"><?php echo $rProfile['profile_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="rtmp_output">Output RTMP <i title="Feed stream to the RTMP server for output to RTMP clients." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-3">
                                                        <input name="rtmp_output" id="rtmp_output" type="checkbox" <?php if (isset($rChannel) && $rChannel['rtmp_output'] == 1) echo 'checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="allow_record">Allow Recording <i title="Allow MAG devices to record this channel." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="allow_record" id="allow_record" type="checkbox" <?php if (!isset($rChannel) || (isset($rChannel) && $rChannel['allow_record'] == 1)) echo 'checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="custom_sid">Custom Channel SID <i title="Here you can specify the SID of the channel in order to work with the epg on the enigma2 devices. You have to specify the code with the ':' but without the first number, 1 or 4097. Example: if we have this code: '1:0:1:13f:157c:13e:820000:0:0:0:2097' then you have to add on this field: ':0:1:13f:157c:13e:820000:0:0:0:'" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="custom_sid" name="custom_sid" value="<?php if (isset($rChannel)) echo htmlspecialchars($rChannel['custom_sid']); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="notes">Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea id="notes" name="notes" class="form-control" rows="3"><?php if (isset($rChannel)) echo htmlspecialchars($rChannel['notes']); ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-pane" id="selection">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_idc">Server Name</label>
                                                    <div class="col-md-8">
                                                        <select id="server_idc" class="form-control select2" data-toggle="select2">
                                                            <?php foreach ($rServers as $rServer): ?>
                                                                <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="category_name">Category / Series</label>
                                                    <div class="col-md-8">
                                                        <select id="category_idv" class="form-control select2" data-toggle="select2">
                                                            <option value="" selected>No Filter</option>
                                                            <?php foreach (getCategories('movie') as $rCategory): ?>
                                                                <option value="0:<?php echo $rCategory['id']; ?>">
                                                                    <?php echo $rCategory['category_name']; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                            <?php foreach (getSeriesList() as $rSeries): ?>
                                                                <option value="1:<?php echo $rSeries['id']; ?>">
                                                                    <?php echo $rSeries['title']; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="vod_search">Search</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="vod_search" value="">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <table id="datatable-movies" class="table table-striped table-borderless nowrap">
                                                        <thead>
                                                            <tr>
                                                                <th class="text-center">ID</th>
                                                                <th>Name</th>
                                                                <th>Category / Series</th>
                                                                <th class="text-center">Actions</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody></tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <span class="float-right">
                                                <li class="nextb list-inline-item">
                                                    <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                                </li>
                                            </span>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="review">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4 stream-url">
                                                    <div class="col-md-12">
                                                        <select multiple id="review_sort" name="review_sort" class="form-control" style="min-height:400px;">
                                                            <?php if (isset($rChannel) && in_array(intval($rProperties['type']), array(2))): ?>
                                                                <?php foreach (json_decode($rChannel['stream_source'], true) as $rSource): ?>
                                                                    <?php $rPath = (substr($rSource, 0, 2) == 's:') ? urldecode(explode(':', $rSource, 3)[2]) : $rSource; ?>
                                                                    <option value="<?php echo $rSource; ?>"><?php echo $rPath; ?></option>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item">
                                                <a href="javascript: void(0);" class="prevb btn btn-secondary"><?php echo $_['prev']; ?></a>
                                                <a href="javascript: void(0);" onClick="MoveUp('review')" class="btn btn-purple"><i class="mdi mdi-chevron-up"></i></a>
                                                <a href="javascript: void(0);" onClick="MoveDown('review')" class="btn btn-purple"><i class="mdi mdi-chevron-down"></i></a>
                                                <a href="javascript: void(0);" onClick="AtoZ('review')" class="btn btn-info">A to Z</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="videos">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4 stream-url">
                                                    <label class="col-md-3 col-form-label" for="import_folder">Import Folder</label>
                                                    <div class="col-md-9 input-group">
                                                        <input type="text" id="import_folder" name="import_folder" readonly class="form-control" value="">
                                                        <div class="input-group-append">
                                                            <a href="#file-browser" id="filebrowser" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-12 add-margin-top-20">
                                                        <select multiple id="videos_sort" name="videos_sort" class="form-control" style="min-height:400px;">
                                                            <?php if (isset($rChannel) && in_array(intval($rProperties['type']), array(1))): ?>
                                                                <?php foreach (json_decode($rChannel['stream_source'], true) as $rSource): ?>
                                                                    <?php $rPath = (substr($rSource, 0, 2) == 's:') ? urldecode(explode(':', $rSource, 3)[2]) : $rSource; ?>
                                                                    <option value="<?php echo $rSource; ?>"><?php echo $rPath; ?></option>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item">
                                                <a href="javascript: void(0);" class="prevb btn btn-secondary"><?php echo $_['prev']; ?></a>
                                                <a href="javascript: void(0);" onClick="MoveUp('videos')" class="btn btn-purple"><i class="mdi mdi-chevron-up"></i></a>
                                                <a href="javascript: void(0);" onClick="MoveDown('videos')" class="btn btn-purple"><i class="mdi mdi-chevron-down"></i></a>
                                                <a href="javascript: void(0);" onClick="Remove('videos')" class="btn btn-warning"><i class="mdi mdi-close"></i></a>
                                                <a href="javascript: void(0);" onClick="AtoZ('videos')" class="btn btn-info">A to Z</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="rtmp-push">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="alert bg-info text-white border-0" role="alert">
                                                    RTMP Push will allow you to push your channels to RTMP servers, such as the one that runs with XC_VM. The `Push From` server needs to be enabled in the servers tab for this to be activated.
                                                </div>
                                                <table id="datatable-rtmp" class="table table-striped table-borderless mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Push From</th>
                                                            <th>RTMP URL</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="rtmp">
                                                        <?php if (isset($rChannel)): ?>
                                                            <?php $rRTMPPush = json_decode($rChannel['external_push'], true); ?>
                                                            <?php if (!$rRTMPPush): ?>
                                                                <?php $rRTMPPush = array(array('')); ?>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php $rRTMPPush = array(array('')); ?>
                                                        <?php endif; ?>

                                                        <?php $i = 0; ?>

                                                        <?php foreach ($rRTMPPush as $rServerID => $rSources): ?>
                                                            <?php foreach ($rSources as $rSource): ?>
                                                                <tr class="rtmp_info">
                                                                    <td class="rtmp_server">
                                                                        <select id="rtmp_push_<?php echo $i; ?>" class="form-control select2" data-toggle="select2">
                                                                            <?php foreach ($rServers as $rServer): ?>
                                                                                <option value="<?php echo $rServer['id']; ?>" <?php echo (isset($rChannel) && $rServerID == $rServer['id']) ? ' selected' : ''; ?>>
                                                                                    <?php echo $rServer['server_name']; ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </td>
                                                                    <td class="input-group">
                                                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($rSource); ?>">
                                                                        <div class="input-group-append">
                                                                            <button class="btn btn-danger waves-effect waves-light btn-fixed-xs" onClick="removeRTMP(this);" type="button"><i class="mdi mdi-close"></i></button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                                <?php $i++; ?>
                                                            <?php endforeach; ?>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="padding-top: 30px;">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <a onClick="addRTMP();" class="btn btn-info btn-pointer">Add RTMP URL</a>
                                                <a href="javascript: void(0);" class="btn nextb btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="servers">Server Tree</label>
                                                    <div class="col-md-8">
                                                        <div id="server_tree"></div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="on_demand">On-Demand Servers</label>
                                                    <div class="col-md-8">
                                                        <select name="on_demand[]" id="on_demand" class="form-control select2-multiple select2" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rServers as $rServer): ?>
                                                                <option value="<?php echo $rServer['id']; ?>" <?php echo (isset($rStream) && in_array($rServer['id'], $rOnDemand)) ? ' selected' : ''; ?>>
                                                                    <?php echo $rServer['server_name']; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <?php if (isset($rChannel)): ?>
                                                        <label class="col-md-4 col-form-label" for="reencode_on_edit">Full re-encode on Edit</label>
                                                        <div class="col-md-2">
                                                            <input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                    <?php endif; ?>
                                                    <label class="col-md-4 col-form-label" for="restart_on_edit">
                                                        <?php echo isset($rChannel) ? 'Restart on Edit' : 'Start After Creation'; ?>
                                                    </label>
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
                                                <input name="submit_stream" type="submit" class="btn btn-primary" value="<?php echo isset($rChannel) ? 'Edit' : 'Create'; ?>" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                        <div id="file-browser" class="mfp-hide white-popup-block">
                            <div class="col-12">
                                <div class="form-group row mb-4">
                                    <label class="col-md-4 col-form-label" for="server_id">Server Name</label>
                                    <div class="col-md-8">
                                        <select id="server_id" class="form-control select2" data-toggle="select2">
                                            <?php foreach (getStreamingServers() as $rServer): ?>
                                                <option value="<?php echo $rServer['id']; ?>">
                                                    <?php echo $rServer['server_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group row mb-4">
                                    <label class="col-md-4 col-form-label" for="current_path">Current Path</label>
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
                                <div class="float-right">
                                    <input id="select_folder" type="button" class="btn btn-info" value="Add This Directory" />
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