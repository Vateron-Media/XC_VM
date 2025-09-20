<?php
include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['id']) || ($rProfileArr = getTranscodeProfile(CoreUtilities::$rRequest['id']))) {
} else {
    goHome();
}

if (!isset($rProfileArr)) {
} else {
    $rProfileOptions = json_decode($rProfileArr['profile_options'], true);

    if ($rProfileOptions['software_decoding']) {
        if (!isset($rProfileOptions[9])) {
        } else {
            $rProfileOptions['gpu']['resize'] = str_replace(':', 'x', $rProfileOptions[9]['val']);
        }

        $rProfileOptions['gpu']['deint'] = intval(isset($rProfileOptions[17]));
    } else {
        if (!isset($rProfileOptions['gpu']['resize'])) {
        } else {
            $rProfileOptions[9]['val'] = str_replace('x', ':', $rProfileOptions['gpu']['resize']);
        }

        $rProfileOptions[17]['val'] = 0 < intval($rProfileOptions['gpu']['deint']);
    }
}

$rDevices = array('Off');

foreach ($rServers as $rServer) {
    $rServer['gpu_info'] = json_decode($rServer['gpu_info'], true);

    if (!isset($rServer['gpu_info']['gpus'])) {
    } else {
        foreach ($rServer['gpu_info']['gpus'] as $rGPUID => $rGPU) {
            $rDevices[$rServer['id'] . '_' . $rGPUID] = $rServer['server_name'] . ' - ' . $rGPU['name'];
        }
    }
}
$_TITLE = 'Transcoding Profile';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        } else {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo isset($rProfileArr) ? $_['edit_profile'] : $_['add_profile']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_FAILURE) { ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?php echo $_['generic_fail']; ?>
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (isset($rProfileArr)) { ?>
                                <input type="hidden" name="edit" value="<?php echo $rProfileArr['profile_id']; ?>" />
                            <?php } ?>
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#profile-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="profile-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label" for="profile_name"><?php echo $_['profile_name']; ?></label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="profile_name" name="profile_name" value="<?php echo isset($rProfileArr) ? htmlspecialchars($rProfileArr['profile_name']) : ''; ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label" for="gpu_device">GPU Accelerated Transcoding</label>
                                                    <div class="col-md-9">
                                                        <select id="gpu_device" name="gpu_device" class="form-control" data-toggle="select2">
                                                            <?php foreach ($rDevices as $rDeviceID => $rDeviceName) { ?>
                                                                <option value="<?php echo $rDeviceID; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['gpu']['val'] == $rDeviceID) {
                                                                                                                echo 'selected';
                                                                                                            } ?>>
                                                                    <?php echo $rDeviceName; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label" for="video_codec"><?php echo $_['video_codec']; ?></label>
                                                    <div class="col-md-9" id="video_codec_cpu_container">
                                                        <select id="video_codec_cpu" name="video_codec_cpu" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('copy' => 'Copy Video Codec', 'libx264' => 'H.264 / MPEG-4 AVC', 'libx265' => 'H.265 / HEVC', 'mpegvideo' => 'H.262 / MPEG-2') as $rCodec => $rCodecName) { ?>
                                                                <option <?php echo (isset($rProfileArr) && $rProfileOptions['-vcodec'] == $rCodec) ? 'selected ' : ''; ?>value="<?php echo $rCodec; ?>"><?php echo $rCodec . ' - ' . $rCodecName; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6" id="video_codec_gpu_container" style="display: none;">
                                                        <select id="video_codec_gpu" name="video_codec_gpu" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('h264_nvenc' => 'CUVID NVENC H264', 'hevc_nvenc' => 'CUVID NVENC HEVC') as $rCodec => $rCodecName) { ?>
                                                                <option <?php echo (isset($rProfileArr) && $rProfileOptions['-vcodec'] == $rCodec) ? 'selected ' : ''; ?>value="<?php echo $rCodec; ?>"><?php echo $rCodec . ' - ' . $rCodecName; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3" id="video_decoding_container" style="display: none;">
                                                        <select id="software_decoding" name="software_decoding" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('Hardware Decoding', 'Software Decoding') as $rValue => $rType) { ?>
                                                                <option <?php echo (isset($rProfileArr) && $rProfileOptions['software_decoding'] == $rValue) ? 'selected ' : ''; ?>value="<?php echo $rValue; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label" for="audio_codec"><?php echo $_['audio_codec']; ?></label>
                                                    <div class="col-md-9">
                                                        <select id="audio_codec" name="audio_codec" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('copy' => 'Copy Audio Codec', 'aac' => 'AAC Advanced Audio Coding', 'ac3' => 'AC3 Dolby Digital', 'eac3' => 'E-AC3 Dolby Digital Plus', 'mp2' => 'MP2 MPEG Audio Layer 2', 'libmp3lame' => 'MP3 MPEG Audio Layer 3') as $rCodec => $rCodecName) { ?>
                                                                <option <?php echo (isset($rProfileArr) && $rProfileOptions['-acodec'] == $rCodec) ? 'selected ' : ''; ?>value="<?php echo $rCodec; ?>"><?php echo $rCodec . ' - ' . $rCodecName; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div id="gpu_h264" style="display:none;">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="preset_h264"><?php echo $_['preset']; ?> <i title="<?php echo $_['profile_tooltip_1']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <select id="preset_h264" name="preset_h264" class="form-control" data-toggle="select2">
                                                                <?php foreach (['' => 'Default', 'losslesshp' => 'Lossless - High Performance', 'lossless' => 'Lossless', 'llhp' => 'Low Latency - High Performance', 'llhq' => 'Low Latency - High Quality', 'll' => 'Low Latency', 'bd' => 'Blu-Ray Disk', 'hq' => 'High Quality', 'hp' => 'High Performance', 'fast' => 'Fast', 'medium' => 'Medium', 'slow' => 'Slow'] as $rPreset => $rPresetName): ?>
                                                                    <option value="<?php echo $rPreset; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['-preset'] == $rPreset) echo 'selected'; ?>><?php echo $rPresetName; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="video_profile_h264"><?php echo $_['video_profile']; ?></label>
                                                        <div class="col-md-3">
                                                            <select id="video_profile_h264" name="video_profile_h264" class="form-control" data-toggle="select2">
                                                                <?php foreach (['' => 'Automatic', 'baseline -level 3.0' => 'Baseline - Level 3.0', 'baseline -level 3.1' => 'Baseline - Level 3.1', 'main -level 3.1' => 'Main - Level 3.1', 'main -level 4.0' => 'Main - Level 4.0', 'high -level 4.0' => 'High - Level 4.0', 'high -level 4.1' => 'High - Level 4.1', 'high -level 4.2' => 'High - Level 4.2', 'high -level 5.0' => 'High - Level 5.0', 'high -level 5.1' => 'High - Level 5.1', 'high444p -level 4.0' => 'High 444p - Level 4.0', 'high444p -level 4.1' => 'High 444p - Level 4.1', 'high444p -level 4.2' => 'High 444p - Level 4.2', 'high444p -level 5.0' => 'High 444p - Level 5.0', 'high444p -level 5.1' => 'High 444p - Level 5.1'] as $rPreset => $rPresetName): ?>
                                                                    <option value="<?php echo $rPreset; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['-profile:v'] == $rPreset) echo 'selected'; ?>><?php echo $rPresetName; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div id="gpu_hevc" style="display:none;">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="preset_hevc"><?php echo $_['preset']; ?> <i title="<?php echo $_['profile_tooltip_1']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <select id="preset_hevc" name="preset_hevc" class="form-control" data-toggle="select2">
                                                                <?php foreach (['' => 'Default', 'losslesshp' => 'Lossless - High Performance', 'lossless' => 'Lossless', 'llhp' => 'Low Latency - High Performance', 'llhq' => 'Low Latency - High Quality', 'll' => 'Low Latency', 'bd' => 'Blu-Ray Disk', 'hq' => 'High Quality', 'hp' => 'High Performance', 'fast' => 'Fast', 'medium' => 'Medium', 'slow' => 'Slow'] as $rPreset => $rPresetName): ?>
                                                                    <option value="<?php echo $rPreset; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['-preset'] == $rPreset) echo 'selected'; ?>><?php echo $rPresetName; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="video_profile_hevc"><?php echo $_['video_profile']; ?></label>
                                                        <div class="col-md-3">
                                                            <select id="video_profile_hevc" name="video_profile_hevc" class="form-control" data-toggle="select2">
                                                                <?php foreach (['' => 'Automatic', 'main -level 4.0' => 'Main - Level 4.0', 'main -level 4.1' => 'Main - Level 4.1', 'main -level 4.2' => 'Main - Level 4.2', 'main -level 5.0' => 'Main - Level 5.0', 'main -level 5.1' => 'Main - Level 5.1', 'main -level 5.2' => 'Main - Level 5.2', 'main -level 6.0' => 'Main - Level 6.0', 'main -level 6.1' => 'Main - Level 6.1', 'main -level 6.2' => 'Main - Level 6.2', 'main10 -level 4.0' => 'Main 10bit - Level 4.0', 'main10 -level 4.1' => 'Main 10bit - Level 4.1', 'main10 -level 4.2' => 'Main 10bit - Level 4.2', 'main10 -level 5.0' => 'Main 10bit - Level 5.0', 'main10 -level 5.1' => 'Main 10bit - Level 5.1', 'main10 -level 5.2' => 'Main 10bit - Level 5.2', 'main10 -level 6.0' => 'Main 10bit - Level 6.0', 'main10 -level 6.1' => 'Main 10bit - Level 6.1', 'main10 -level 6.2' => 'Main 10bit - Level 6.2', 'rext -level 4.0' => 'REXT - Level 4.0', 'rext -level 4.1' => 'REXT - Level 4.1', 'rext -level 4.2' => 'REXT - Level 4.2', 'rext -level 5.0' => 'REXT - Level 5.0', 'rext -level 5.1' => 'REXT - Level 5.1', 'rext -level 5.2' => 'REXT - Level 5.2', 'rext -level 6.0' => 'REXT - Level 6.0', 'rext -level 6.1' => 'REXT - Level 6.1', 'rext -level 6.2' => 'REXT - Level 6.2'] as $rPreset => $rPresetName): ?>
                                                                    <option value="<?php echo $rPreset; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['-profile:v'] == $rPreset) echo 'selected'; ?>><?php echo $rPresetName; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div id="gpu_options" style="display:none;">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="resize">Resize <i title="Resize command for GPU acceleration. Example: 1920x1080" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="resize" name="resize" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions['gpu']['resize']); ?>">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="deint">Deinterlace <i title="Set deinterlacing mode." class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <select name="deint" class="form-control" data-toggle="select2">
                                                                <?php foreach (['Weave (default)', 'Bob', 'Adaptive'] as $rInt => $rValue): ?>
                                                                    <option value="<?php echo $rInt; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['gpu']['deint'] == $rInt) echo 'selected'; ?>><?php echo $rValue; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div id="cpu_options">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="preset_cpu"><?php echo $_['preset']; ?> <i title="<?php echo $_['profile_tooltip_1']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <select id="preset_cpu" name="preset_cpu" class="form-control" data-toggle="select2">
                                                                <?php foreach (['' => 'Default', 'ultrafast' => 'Ultra Fast', 'superfast' => 'Super Fast', 'veryfast' => 'Very Fast', 'faster' => 'Faster', 'fast' => 'Fast', 'medium' => 'Medium', 'slow' => 'Slow', 'slower' => 'Slower', 'veryslow' => 'Very Slow', 'placebo' => 'Placebo'] as $rPreset => $rPresetName): ?>
                                                                    <option value="<?php echo $rPreset; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['-preset'] == $rPreset) echo 'selected'; ?>><?php echo $rPresetName; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="video_profile_cpu"><?php echo $_['video_profile']; ?></label>
                                                        <div class="col-md-3">
                                                            <select id="video_profile_cpu" name="video_profile_cpu" class="form-control" data-toggle="select2">
                                                                <?php foreach (['' => 'Automatic', 'baseline -level 3.0' => 'Baseline - Level 3.0', 'baseline -level 3.1' => 'Baseline - Level 3.1', 'main -level 3.1' => 'Main - Level 3.1', 'main -level 4.0' => 'Main - Level 4.0', 'high -level 4.0' => 'High - Level 4.0', 'high -level 4.1' => 'High - Level 4.1', 'high -level 4.2' => 'High - Level 4.2', 'high -level 5.0' => 'High - Level 5.0', 'high -level 5.1' => 'High - Level 5.1'] as $rPreset => $rPresetName): ?>
                                                                    <option value="<?php echo $rPreset; ?>" <?php if (isset($rProfileArr) && $rProfileOptions['-profile:v'] == $rPreset) echo 'selected'; ?>><?php echo $rPresetName; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="scaling"><?php echo $_['scaling']; ?> <i title="<?php echo $_['profile_tooltip_9']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="scaling" name="scaling" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions['scaling']); ?>">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="yadif_filter">Enable Deinterlace Filter <i title="<?php echo $_['profile_tooltip_1']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input name="yadif_filter" id="yadif_filter" type="checkbox" <?php if (isset($rProfileArr) && $rProfileOptions[17]['val'] == 1) echo 'checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="video_bitrate"><?php echo $_['average_video_bitrate']; ?> <i title="<?php echo $_['profile_tooltip_3']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="video_bitrate" name="video_bitrate" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[3]['val']); ?>">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="audio_bitrate"><?php echo $_['average_audio_bitrate']; ?> <i title="<?php echo $_['profile_tooltip_4']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="audio_bitrate" name="audio_bitrate" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[4]['val']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="min_tolerance"><?php echo $_['minimum_bitrate_tolerance']; ?> <i title="<?php echo $_['profile_tooltip_5']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="min_tolerance" name="min_tolerance" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[5]['val']); ?>">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="max_tolerance"><?php echo $_['maximum_bitrate_tolerance']; ?> <i title="<?php echo $_['profile_tooltip_6']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="max_tolerance" name="max_tolerance" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[6]['val']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="buffer_size"><?php echo $_['buffer_size']; ?> <i title="<?php echo $_['profile_tooltip_7']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="buffer_size" name="buffer_size" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[7]['val']); ?>">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="crf_value"><?php echo $_['crf_value']; ?> <i title="<?php echo $_['profile_tooltip_8']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="crf_value" name="crf_value" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[8]['val']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="framerate"><?php echo $_['target_framerate']; ?> <i title="<?php echo $_['profile_tooltip_11']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="framerate" name="framerate" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[11]['val']); ?>">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="samplerate"><?php echo $_['audio_sample_rate']; ?> <i title="<?php echo $_['profile_tooltip_12']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="samplerate" name="samplerate" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[12]['val']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="audio_channels"><?php echo $_['audio_channels']; ?> <i title="<?php echo $_['profile_tooltip_13']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="audio_channels" name="audio_channels" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[13]['val']); ?>">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="threads"><?php echo $_['threads']; ?> <i title="<?php echo $_['profile_tooltip_14']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="threads" name="threads" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[15]['val']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="aspect_ratio"><?php echo $_['aspect_ratio']; ?> <i title="<?php echo $_['profile_tooltip_10']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="aspect_ratio" name="aspect_ratio" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[10]['val']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="logo_path"><?php echo $_['logo_path_url']; ?> <i title="<?php echo $_['profile_tooltip_16']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-6">
                                                            <input type="text" class="form-control" id="logo_path" name="logo_path" value="<?php if (isset($rProfileArr)) echo htmlspecialchars($rProfileOptions[16]['val']); ?>">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center" id="logo_pos" name="logo_pos" value="<?php echo isset($rProfileArr) ? htmlspecialchars($rProfileOptions[16]['pos'] ?? '10:10') : '10:10'; ?>" placeholder="pos x:x">
                                                        </div>
                                                    </div>
                                                    <ul class="list-inline wizard mb-0">
                                                        <li class="list-inline-item float-right">
                                                            <input name="submit_profile" type="submit" class="btn btn-primary" value="<?php echo isset($rProfileArr) ? $_['edit'] : $_['add']; ?>">
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
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


    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        })
        $("#gpu_device").change(function() {
            if ($(this).val() == 0) {
                $("#video_codec_cpu_container").show();
                $("#video_codec_gpu_container").hide();
                $("#video_decoding_container").hide();
                $("#gpu_options").hide();
                $("#cpu_options").show();
                $("#gpu_hevc").hide();
                $("#gpu_h264").hide();

            } else {
                $("#video_codec_cpu_container").hide();
                $("#video_codec_gpu_container").show();
                $("#video_decoding_container").show();
                $("#gpu_options").show();
                $("#cpu_options").hide();
                $("#video_codec_gpu").trigger("change");
            }
        });
        $("#video_codec_gpu").change(function() {
            if ($("#gpu_device").val() != 0) {
                if ($(this).val() == "h264_nvenc") {
                    $("#gpu_hevc").hide();
                    $("#gpu_h264").show();
                } else {
                    $("#gpu_hevc").show();
                    $("#gpu_h264").hide();
                }
            }
        });
        $("#video_bitrate").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#audio_bitrate").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#min_tolerance").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#max_tolerance").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#buffer_size").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#framerate").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#samplerate").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#audio_channels").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#threads").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#crf_value").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#gpu_device").trigger("change");
        $("#video_codec_gpu").trigger("change");
        $("form").submit(function(e) {
            e.preventDefault();
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>