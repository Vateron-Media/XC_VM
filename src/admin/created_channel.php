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
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'var changeTitle = false;' . "\r\n\t\t" . 'var rChannels = {};' . "\r\n\t\t\t\t\r\n\t\t";

		if (isset($rChannel) && $rProperties['type'] == 2) {
			echo "\t\t" . 'var rSelection = ';
			echo json_encode(getSelections(json_decode($rChannel['stream_source'], true)));
			echo ';' . "\r\n\t\t";
		} else {
			echo "\t\t" . 'var rSelection = [];' . "\r\n\t\t";
		}

		echo "\r\n" . '        function openImage(elem) {' . "\r\n" . '            var rImage = $(elem).parent().parent().find("input").val();' . "\r\n" . '            if (rImage) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rImage)," . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . 'function AtoZ(rType) {' . "\r\n\t\t\t" . '$("#" + rType + "_sort").append($("#" + rType + "_sort option").remove().sort(function(a, b) {' . "\r\n\t\t\t\t" . 'var at = $(a).text().toUpperCase().split("/").pop(), bt = $(b).text().toUpperCase().split("/").pop();' . "\r\n\t\t\t\t" . 'return (at > bt) ? 1 : ((at < bt) ? -1 : 0);' . "\r\n\t\t\t" . '}));' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveUp(rType) {' . "\r\n\t\t\t" . "var rSelected = \$('#' + rType + '_sort option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . 'var rPrevious = rSelected.first().prev()[0];' . "\r\n\t\t\t\t" . "if (\$(rPrevious).html() != '') {" . "\r\n\t\t\t\t\t" . 'rSelected.first().prev().before(rSelected);' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveDown(rType) {' . "\r\n\t\t\t" . "var rSelected = \$('#' + rType + '_sort option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . 'rSelected.last().next().after(rSelected);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function Remove(rType) {' . "\r\n\t\t\t" . "var rSelected = \$('#' + rType + '_sort option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . 'rSelected.remove();' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        function addRTMP() {' . "\r\n\t\t\t" . '$(".rtmp_info:first").clone().appendTo(".rtmp");' . "\r\n" . "            \$(\".rtmp_info:last .rtmp_server\").html('<select id=\"rtmp_push_' + Math.random().toString(16).substring(2) + '\" class=\"form-control\" data-toggle=\"select2\">";

		foreach ($rServers as $rServer) {
			echo '<option value="';
			echo $rServer['id'];
			echo '"';

			if (!(isset($rStream) && $rServerID == $rServer['id'])) {
			} else {
				echo ' selected';
			}

			echo '>';
			echo $rServer['server_name'];
			echo '</option>';
		}
		echo "</select>');" . "\r\n" . "            \$(\".rtmp_info:last select\").select2({width: '100%'});" . "\r\n\t\t\t" . '$(".rtmp_info:last input").val("");' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function removeRTMP(elem) {' . "\r\n\t\t\t" . "if (\$('.rtmp_info').length > 1) {" . "\r\n\t\t\t\t" . '$(elem).parent().parent().parent().remove();' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$(elem).parent().parent().parent().find("input").val("");' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        ' . "\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category_idv").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getServer() {' . "\r\n\t\t\t" . 'return $("#server_idc").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleSelection(rID) {' . "\r\n\t\t\t" . 'var rIndex = rSelection.indexOf(parseInt(rID));' . "\r\n\t\t\t" . 'if (rIndex > -1) {' . "\r\n\t\t\t\t" . 'rSelection = jQuery.grep(rSelection, function(rValue) {' . "\r\n\t\t\t\t\t" . 'return parseInt(rValue) != parseInt(rID);' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . 'rSelection.push(parseInt(rID));' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . '$("#datatable-movies").DataTable().ajax.reload(null, false);' . "\r\n\t\t\t" . 'reviewSelection();' . "\r\n\t\t" . '}' . "\r\n\r\n\t\t" . 'function reviewSelection() {' . "\r\n\t\t\t" . '$.post("./api?action=review_selection", {"data": rSelection}, function(rData) {' . "\r\n\t\t\t\t" . 'if (rData.result === true) {' . "\r\n\t\t\t\t\t" . 'var rActiveStreams = [];' . "\r\n\t\t\t\t\t" . '$(rData.streams).each(function(rIndex) {' . "\r\n\t\t\t\t\t\t" . 'rStreamSource = $.parseJSON(rData.streams[rIndex]["stream_source"])[0];' . "\r\n\t\t\t\t\t\t" . 'rActiveStreams.push(rStreamSource);' . "\r\n\t\t\t\t\t\t" . "rExt = rStreamSource.split('.').pop().toLowerCase();" . "\r\n\t\t\t\t\t\t" . "if (([\"mp4\", \"mkv\", \"mov\", \"avi\", \"mpg\", \"mpeg\", \"flv\", \"wmv\", \"m4v\"].includes(rExt)) && (\$(\"#review_sort option[value='\" + rStreamSource.replace(\"'\", \"\\\\'\") + \"']\").length == 0)) {" . "\r\n\t\t\t\t\t\t\t" . '$("#review_sort").append(new Option(rStreamSource, rStreamSource));' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t" . '$("#review_sort option").each(function() {' . "\r\n\t\t\t\t\t\t" . 'if (!rActiveStreams.includes($(this).val())) {' . "\r\n\t\t\t\t\t\t\t" . '$(this).remove();' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '}, "json");' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectDirectory(elem) {' . "\r\n\t\t\t" . 'window.currentDirectory += elem + "/";' . "\r\n\t\t\t" . '$("#current_path").val(window.currentDirectory);' . "\r\n\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectParent() {' . "\r\n\t\t\t" . '$("#current_path").val(window.currentDirectory.split("/").slice(0,-2).join("/") + "/");' . "\r\n\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t" . '}' . "\r\n" . '        function evaluateServers() {' . "\r\n" . '            var rOVal = $("#on_demand").val();' . "\r\n" . '            $("#on_demand").empty();' . "\r\n" . "            \$(\$('#server_tree').jstree(true).get_json('source', {flat:true})).each(function(index, value) {" . "\r\n" . '                if (value.parent != "#") {' . "\r\n" . '                    $("#on_demand").append(new Option(value.text, value.id));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#on_demand").val(rOVal).trigger("change");' . "\r\n" . '            if (!$("#on_demand").val()) {' . "\r\n" . '                $("#on_demand").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('.select2').select2({width: '100%'});" . "\r\n" . '            $("#category_id").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#category_id').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#category_create").show();' . "\r\n" . "                    \$(\"#category_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#category_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#category_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n" . '            $("#bouquets").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#bouquets').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#bouquet_create").show();' . "\r\n" . "                    \$(\"#bouquet_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#bouquet_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#bouquet_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'scrollY: "250px",' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0]},' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"language": {' . "\r\n\t\t\t\t\t" . '"emptyTable": ""' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t\r\n\t\t\t" . '$("#datatable-files").DataTable({' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'scrollY: "250px",' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0]},' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"language": {' . "\r\n\t\t\t\t\t" . '"emptyTable": "No compatible files found"' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t\r\n\t\t\t" . '$("#datatable-movies").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'createdRow: function(row, data, index) {' . "\r\n\t\t\t\t\t" . "\$(row).addClass('vod-' + data[0]);" . "\r\n\t\t\t\t\t" . 'var rIndex = rSelection.indexOf(parseInt(data[0]));' . "\r\n\t\t\t\t\t" . 'if (rIndex > -1) {' . "\r\n\t\t\t\t\t\t" . '$(row).find(".btn-remove").show();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$(row).find(".btn-add").show();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'bAutoWidth: false,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n\t\t\t\t" . 'lengthChange: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "vod_selection";' . "\r\n\t\t\t\t\t\t" . 'd.category_id = getCategory();' . "\r\n\t\t\t\t\t\t" . 'd.server_id = getServer();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t\r\n\t\t\t" . '$("#category_idv").on("select2:select", function(e) { ' . "\r\n\t\t\t\t" . '$("#datatable-movies").DataTable().ajax.reload(null, false);' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#vod_search').keyup(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-movies').DataTable().search(\$(this).val()).draw();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#transcode_profile_id").change(function() {' . "\r\n" . '                if ((($(this)).val() == 0) || (($(this)).val() == -1)) {' . "\r\n" . '                    $("#warning").show();' . "\r\n" . '                } else {' . "\r\n" . '                    $("#warning").hide();' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#select_folder").click(function() {' . "\r\n\t\t\t\t" . '$("#import_folder").val($("#server_id option:selected").text());' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().rows().every(function ( rowIdx, tableLoop, rowLoop) {' . "\r\n\t\t\t\t\t" . 'var data = this.data();' . "\r\n\t\t\t\t\t" . "rExt = data[1].split('.').pop().toLowerCase();" . "\r\n\t\t\t\t\t" . "if (([\"mp4\", \"mkv\", \"mov\", \"avi\", \"mpg\", \"mpeg\", \"flv\", \"wmv\", \"m4v\"].includes(rExt)) && (\$(\"#videos_sort option[value='\" + (\"s:\" + \$(\"#server_id option:selected\").val() + \":\" + window.currentDirectory + data[1]).replace(\"'\", \"\\\\'\") + \"']\").length == 0)) {" . "\r\n\t\t\t\t\t\t" . '$("#videos_sort").append(new Option(window.currentDirectory + data[1], "s:" + $("#server_id option:selected").val() + ":" + window.currentDirectory + data[1]));' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$.magnificPopup.close();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#changeDir").click(function() {' . "\r\n\t\t\t\t" . 'window.currentDirectory = $("#current_path").val();' . "\r\n\t\t\t\t" . 'if (window.currentDirectory.substr(-1) != "/") {' . "\r\n\t\t\t\t\t" . 'window.currentDirectory += "/";' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$("#current_path").val(window.currentDirectory);' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().clear();' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().row.add(["", "Loading..."]);' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().draw(true);' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().clear();' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().row.add(["", "Please wait..."]);' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().draw(true);' . "\r\n\t\t\t\t" . 'rFilter = "video";' . "\r\n\t\t\t\t" . '$.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=" + rFilter, function(data) {' . "\r\n\t\t\t\t\t" . '$("#datatable").DataTable().clear();' . "\r\n\t\t\t\t\t" . '$("#datatable-files").DataTable().clear();' . "\r\n\t\t\t\t\t" . 'if (window.currentDirectory != "/") {' . "\r\n\t\t\t\t\t\t" . "\$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-subdirectory-arrow-left'></i>\", \"Parent Directory\"]);" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t" . '$(data.data.dirs).each(function(id, dir) {' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-folder-open-outline'></i>\", dir]);" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t" . '$("#datatable").DataTable().draw(true);' . "\r\n\t\t\t\t\t\t" . '$(data.data.files).each(function(id, dir) {' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#datatable-files\").DataTable().row.add([\"<i class='mdi mdi-file-video'></i>\", dir]);" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t" . '$("#datatable-files").DataTable().draw(true);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#datatable').on('click', 'tbody > tr', function() {" . "\r\n\t\t\t\t" . 'if ($(this).find("td").eq(1).html() == "Parent Directory") {' . "\r\n\t\t\t\t\t" . 'selectParent();' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'selectDirectory($(this).find("td").eq(1).html());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#server_tree').on('redraw.jstree', function (e, data) {" . "\r\n" . '                evaluateServers();' . "\r\n" . "            }).on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n" . '                    ' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\t\t\t\r\n\t\t\t" . '$("#filebrowser").magnificPopup({' . "\r\n\t\t\t\t" . "type: 'inline'," . "\r\n\t\t\t\t" . 'preloader: false,' . "\r\n\t\t\t\t" . "focus: '#server_id'," . "\r\n\t\t\t\t" . 'callbacks: {' . "\r\n\t\t\t\t\t" . 'beforeOpen: function() {' . "\r\n\t\t\t\t\t\t" . 'if ($(window).width() < 830) {' . "\r\n\t\t\t\t\t\t\t" . 'this.st.focus = false;' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . "this.st.focus = '#server_id';" . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#filebrowser-sub").magnificPopup({' . "\r\n\t\t\t\t" . "type: 'inline'," . "\r\n\t\t\t\t" . 'preloader: false,' . "\r\n\t\t\t\t" . "focus: '#server_id'," . "\r\n\t\t\t\t" . 'callbacks: {' . "\r\n\t\t\t\t\t" . 'beforeOpen: function() {' . "\r\n\t\t\t\t\t\t" . 'if ($(window).width() < 830) {' . "\r\n\t\t\t\t\t\t\t" . 'this.st.focus = false;' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . "this.st.focus = '#server_id';" . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#filebrowser").on("mfpOpen", function() {' . "\r\n\t\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t\t\t" . "\$(\$.fn.dataTable.tables(true)).css('width', '100%');" . "\r\n\t\t\t\t" . '$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#filebrowser-sub").on("mfpOpen", function() {' . "\r\n\t\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t\t\t" . "\$(\$.fn.dataTable.tables(true)).css('width', '100%');" . "\r\n\t\t\t\t" . '$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#server_id").change(function() {' . "\r\n\t\t\t\t" . '$("#current_path").val("/");' . "\r\n\t\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#series_no").change(function() {' . "\r\n\t\t\t\t" . 'if ($("#series_no").val() > 0) {' . "\r\n\t\t\t\t\t" . '$("#stream_display_name").val("24/7 " + $("#series_no option:selected").text());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#channel_type").change(function() {' . "\r\n\t\t\t\t" . 'if ($("#channel_type").val() == 0) {' . "\r\n\t\t\t\t\t" . '$("#review_nav").hide();' . "\r\n\t\t\t\t\t" . '$("#selection_nav").hide()' . "\r\n\t\t\t\t\t" . '$("#videos_nav").hide();' . "\r\n\t\t\t\t\t" . '$("#series_nav").show();' . "\r\n\t\t\t\t" . '} else if ($("#channel_type").val() == 1) {' . "\r\n\t\t\t\t\t" . '$("#review_nav").hide();' . "\r\n\t\t\t\t\t" . '$("#selection_nav").hide()' . "\r\n\t\t\t\t\t" . '$("#videos_nav").show();' . "\r\n\t\t\t\t\t" . '$("#series_nav").hide();' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$("#review_nav").show();' . "\r\n\t\t\t\t\t" . '$("#selection_nav").show()' . "\r\n\t\t\t\t\t" . '$("#videos_nav").hide();' . "\r\n\t\t\t\t\t" . '$("#series_nav").hide();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#server_idc").change(function() {' . "\r\n\t\t\t\t" . '$("#datatable-movies").DataTable().ajax.reload(null, false);' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t";

		if (!isset($rChannel)) {
		} else {
			echo "\t\t\t" . "\$(\"#season_num\").trigger('change');" . "\r\n" . "            \$(\"#transcode_profile_id\").trigger('change');" . "\r\n" . '            ';
		}

		echo "\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t\t" . "\$(\"#channel_type\").trigger('change');" . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                rSubmit = true;' . "\r\n\t\t\t\t" . 'var rVideoFiles = [];' . "\r\n\t\t\t\t" . 'if ($("#channel_type").val() == 0) {' . "\r\n\t\t\t\t\t" . 'if ($("#series_no").val() == 0) {' . "\r\n\t\t\t\t\t\t" . '$.toast("Please select a series to map.");' . "\r\n" . '                        rSubmit = false;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else if ($("#channel_type").val() == 1) {' . "\r\n\t\t\t\t\t" . 'if ($("#videos_sort option").length == 0) {' . "\r\n\t\t\t\t\t\t" . '$.toast("Please add at least one video to the channel.");' . "\r\n" . '                        rSubmit = false;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#videos_sort option").each(function() {' . "\r\n\t\t\t\t\t\t" . 'rVideoFiles.push($(this).val());' . "\r\n\t\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '} else if ($("#channel_type").val() == 2) {' . "\r\n\t\t\t\t\t" . 'if ($("#review_sort option").length == 0) {' . "\r\n\t\t\t\t\t\t" . '$.toast("Please add at least one video to the channel.");' . "\r\n" . '                        rSubmit = false;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#review_sort option").each(function() {' . "\r\n\t\t\t\t\t\t" . 'rVideoFiles.push($(this).val());' . "\r\n\t\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . 'if (!$("#transcode_profile_id").val()) {' . "\r\n\t\t\t\t\t" . '$.toast("Please select a trancoding profile.");' . "\r\n\t\t\t\t\t" . 'rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . "\$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n\t\t\t\t" . '$("#video_files").val(JSON.stringify(rVideoFiles));' . "\r\n" . '                var rRTMPPush = {};' . "\r\n" . '                $(".rtmp_info").each(function() {' . "\r\n" . '                    rServerID = $(this).find("select").val();' . "\r\n" . '                    rSource = $(this).find("input").val();' . "\r\n" . '                    if (rServerID > 0 && rSource.length > 0) {' . "\r\n" . '                        if (!rRTMPPush[rServerID]) {' . "\r\n" . '                            rRTMPPush[rServerID] = [];' . "\r\n" . '                        }' . "\r\n" . '                        rRTMPPush[rServerID].push(rSource);' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '                $("#external_push").val(JSON.stringify(rRTMPPush));' . "\r\n" . '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>