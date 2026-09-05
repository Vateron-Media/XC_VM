<?php

/**
 * Add/Edit Transcode Profile (Bootstrap 5). Full-page FFmpeg codec/bitrate/resolution
 * editor: CPU or GPU (NVENC) pipeline, video/audio codecs, presets, bitrates, tolerances,
 * scaling/deinterlace, framerate, logo overlay. Saves via post.php?action=profile
 * (ProfileService::process). Reached full-page in the new-UI shell from the profiles table.
 */

use XcVm\Core\Config\SettingsManager;

$rEdit = isset($rProfileArr);
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $rEdit ? $language::get('edit_profile') : $language::get('add_profile'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="profile-form">
            <?php if ($rEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rProfileArr['profile_id']; ?>">
            <?php endif; ?>

            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="profile_name"><?= $language::get('profile_name'); ?></label>
                <div class="col-md-9"><input type="text" class="form-control" id="profile_name" name="profile_name" value="<?= $rEdit ? htmlspecialchars((string) $rProfileArr['profile_name'], ENT_QUOTES) : ''; ?>" required></div>
            </div>

            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="gpu_device"><?= $language::get('gpu_accelerated_transcoding'); ?></label>
                <div class="col-md-9">
                    <select id="gpu_device" name="gpu_device" class="form-select">
                        <?php foreach ($rDevices as $rDeviceID => $rDeviceName): ?>
                            <option value="<?= htmlspecialchars((string) $rDeviceID, ENT_QUOTES); ?>" <?= ($rEdit && ($rProfileOptions['gpu']['val'] ?? 0) == $rDeviceID) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rDeviceName, ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="video_codec"><?= $language::get('video_codec'); ?></label>
                <div class="col-md-9" id="video_codec_cpu_container">
                    <select id="video_codec_cpu" name="video_codec_cpu" class="form-select">
                        <?php foreach (['copy' => 'Copy Video Codec', 'libx264' => 'H.264 / MPEG-4 AVC', 'libx265' => 'H.265 / HEVC', 'mpegvideo' => 'H.262 / MPEG-2'] as $rCodec => $rCodecName): ?>
                            <option value="<?= $rCodec; ?>" <?= ($rEdit && ($rProfileOptions['-vcodec'] ?? '') == $rCodec) ? 'selected' : ''; ?>><?= $rCodec . ' - ' . $rCodecName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6" id="video_codec_gpu_container" style="display: none;">
                    <select id="video_codec_gpu" name="video_codec_gpu" class="form-select">
                        <?php foreach (['h264_nvenc' => 'CUVID NVENC H264', 'hevc_nvenc' => 'CUVID NVENC HEVC'] as $rCodec => $rCodecName): ?>
                            <option value="<?= $rCodec; ?>" <?= ($rEdit && ($rProfileOptions['-vcodec'] ?? '') == $rCodec) ? 'selected' : ''; ?>><?= $rCodec . ' - ' . $rCodecName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="video_decoding_container" style="display: none;">
                    <select id="software_decoding" name="software_decoding" class="form-select">
                        <?php foreach (['Hardware Decoding', 'Software Decoding'] as $rValue => $rType): ?>
                            <option value="<?= $rValue; ?>" <?= ($rEdit && ($rProfileOptions['software_decoding'] ?? 0) == $rValue) ? 'selected' : ''; ?>><?= $rType; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="audio_codec"><?= $language::get('audio_codec'); ?></label>
                <div class="col-md-9">
                    <select id="audio_codec" name="audio_codec" class="form-select">
                        <?php foreach (['copy' => 'Copy Audio Codec', 'aac' => 'AAC Advanced Audio Coding', 'ac3' => 'AC3 Dolby Digital', 'eac3' => 'E-AC3 Dolby Digital Plus', 'mp2' => 'MP2 MPEG Audio Layer 2', 'libmp3lame' => 'MP3 MPEG Audio Layer 3'] as $rCodec => $rCodecName): ?>
                            <option value="<?= $rCodec; ?>" <?= ($rEdit && ($rProfileOptions['-acodec'] ?? '') == $rCodec) ? 'selected' : ''; ?>><?= $rCodec . ' - ' . $rCodecName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="gpu_h264" style="display: none;">
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="preset_h264"><?= $language::get('preset'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_1'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3">
                        <select id="preset_h264" name="preset_h264" class="form-select">
                            <?php foreach (['' => 'Default', 'losslesshp' => 'Lossless - High Performance', 'lossless' => 'Lossless', 'llhp' => 'Low Latency - High Performance', 'llhq' => 'Low Latency - High Quality', 'll' => 'Low Latency', 'bd' => 'Blu-Ray Disk', 'hq' => 'High Quality', 'hp' => 'High Performance', 'fast' => 'Fast', 'medium' => 'Medium', 'slow' => 'Slow'] as $rPreset => $rPresetName): ?>
                                <option value="<?= $rPreset; ?>" <?= ($rEdit && ($rProfileOptions['-preset'] ?? '') == $rPreset) ? 'selected' : ''; ?>><?= $rPresetName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="col-md-3 col-form-label" for="video_profile_h264"><?= $language::get('video_profile'); ?></label>
                    <div class="col-md-3">
                        <select id="video_profile_h264" name="video_profile_h264" class="form-select">
                            <?php foreach (['' => 'Automatic', 'baseline -level 3.0' => 'Baseline - Level 3.0', 'baseline -level 3.1' => 'Baseline - Level 3.1', 'main -level 3.1' => 'Main - Level 3.1', 'main -level 4.0' => 'Main - Level 4.0', 'high -level 4.0' => 'High - Level 4.0', 'high -level 4.1' => 'High - Level 4.1', 'high -level 4.2' => 'High - Level 4.2', 'high -level 5.0' => 'High - Level 5.0', 'high -level 5.1' => 'High - Level 5.1', 'high444p -level 4.0' => 'High 444p - Level 4.0', 'high444p -level 4.1' => 'High 444p - Level 4.1', 'high444p -level 4.2' => 'High 444p - Level 4.2', 'high444p -level 5.0' => 'High 444p - Level 5.0', 'high444p -level 5.1' => 'High 444p - Level 5.1'] as $rPreset => $rPresetName): ?>
                                <option value="<?= $rPreset; ?>" <?= ($rEdit && ($rProfileOptions['-profile:v'] ?? '') == $rPreset) ? 'selected' : ''; ?>><?= $rPresetName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div id="gpu_hevc" style="display: none;">
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="preset_hevc"><?= $language::get('preset'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_1'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3">
                        <select id="preset_hevc" name="preset_hevc" class="form-select">
                            <?php foreach (['' => 'Default', 'losslesshp' => 'Lossless - High Performance', 'lossless' => 'Lossless', 'llhp' => 'Low Latency - High Performance', 'llhq' => 'Low Latency - High Quality', 'll' => 'Low Latency', 'bd' => 'Blu-Ray Disk', 'hq' => 'High Quality', 'hp' => 'High Performance', 'fast' => 'Fast', 'medium' => 'Medium', 'slow' => 'Slow'] as $rPreset => $rPresetName): ?>
                                <option value="<?= $rPreset; ?>" <?= ($rEdit && ($rProfileOptions['-preset'] ?? '') == $rPreset) ? 'selected' : ''; ?>><?= $rPresetName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="col-md-3 col-form-label" for="video_profile_hevc"><?= $language::get('video_profile'); ?></label>
                    <div class="col-md-3">
                        <select id="video_profile_hevc" name="video_profile_hevc" class="form-select">
                            <?php foreach (['' => 'Automatic', 'main -level 4.0' => 'Main - Level 4.0', 'main -level 4.1' => 'Main - Level 4.1', 'main -level 4.2' => 'Main - Level 4.2', 'main -level 5.0' => 'Main - Level 5.0', 'main -level 5.1' => 'Main - Level 5.1', 'main -level 5.2' => 'Main - Level 5.2', 'main -level 6.0' => 'Main - Level 6.0', 'main -level 6.1' => 'Main - Level 6.1', 'main -level 6.2' => 'Main - Level 6.2', 'main10 -level 4.0' => 'Main 10bit - Level 4.0', 'main10 -level 4.1' => 'Main 10bit - Level 4.1', 'main10 -level 4.2' => 'Main 10bit - Level 4.2', 'main10 -level 5.0' => 'Main 10bit - Level 5.0', 'main10 -level 5.1' => 'Main 10bit - Level 5.1', 'main10 -level 5.2' => 'Main 10bit - Level 5.2', 'main10 -level 6.0' => 'Main 10bit - Level 6.0', 'main10 -level 6.1' => 'Main 10bit - Level 6.1', 'main10 -level 6.2' => 'Main 10bit - Level 6.2', 'rext -level 4.0' => 'REXT - Level 4.0', 'rext -level 4.1' => 'REXT - Level 4.1', 'rext -level 4.2' => 'REXT - Level 4.2', 'rext -level 5.0' => 'REXT - Level 5.0', 'rext -level 5.1' => 'REXT - Level 5.1', 'rext -level 5.2' => 'REXT - Level 5.2', 'rext -level 6.0' => 'REXT - Level 6.0', 'rext -level 6.1' => 'REXT - Level 6.1', 'rext -level 6.2' => 'REXT - Level 6.2'] as $rPreset => $rPresetName): ?>
                                <option value="<?= $rPreset; ?>" <?= ($rEdit && ($rProfileOptions['-profile:v'] ?? '') == $rPreset) ? 'selected' : ''; ?>><?= $rPresetName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div id="gpu_options" style="display: none;">
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="resize"><?= $language::get('resize'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('resize_command_for_gpu_acceleration_example_1920x1080'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="resize" name="resize" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions['gpu']['resize'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <label class="col-md-3 col-form-label" for="deint"><?= $language::get('deinterlace'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('set_deinterlacing_mode'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3">
                        <select id="deint" name="deint" class="form-select">
                            <?php foreach (['Weave (default)', 'Bob', 'Adaptive'] as $rInt => $rValue): ?>
                                <option value="<?= $rInt; ?>" <?= ($rEdit && ($rProfileOptions['gpu']['deint'] ?? 0) == $rInt) ? 'selected' : ''; ?>><?= $rValue; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div id="cpu_options">
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="preset_cpu"><?= $language::get('preset'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_1'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3">
                        <select id="preset_cpu" name="preset_cpu" class="form-select">
                            <?php foreach (['' => 'Default', 'ultrafast' => 'Ultra Fast', 'superfast' => 'Super Fast', 'veryfast' => 'Very Fast', 'faster' => 'Faster', 'fast' => 'Fast', 'medium' => 'Medium', 'slow' => 'Slow', 'slower' => 'Slower', 'veryslow' => 'Very Slow', 'placebo' => 'Placebo'] as $rPreset => $rPresetName): ?>
                                <option value="<?= $rPreset; ?>" <?= ($rEdit && ($rProfileOptions['-preset'] ?? '') == $rPreset) ? 'selected' : ''; ?>><?= $rPresetName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="col-md-3 col-form-label" for="video_profile_cpu"><?= $language::get('video_profile'); ?></label>
                    <div class="col-md-3">
                        <select id="video_profile_cpu" name="video_profile_cpu" class="form-select">
                            <?php foreach (['' => 'Automatic', 'baseline -level 3.0' => 'Baseline - Level 3.0', 'baseline -level 3.1' => 'Baseline - Level 3.1', 'main -level 3.1' => 'Main - Level 3.1', 'main -level 4.0' => 'Main - Level 4.0', 'high -level 4.0' => 'High - Level 4.0', 'high -level 4.1' => 'High - Level 4.1', 'high -level 4.2' => 'High - Level 4.2', 'high -level 5.0' => 'High - Level 5.0', 'high -level 5.1' => 'High - Level 5.1'] as $rPreset => $rPresetName): ?>
                                <option value="<?= $rPreset; ?>" <?= ($rEdit && ($rProfileOptions['-profile:v'] ?? '') == $rPreset) ? 'selected' : ''; ?>><?= $rPresetName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="scaling"><?= $language::get('scaling'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_9'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="scaling" name="scaling" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[9]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <label class="col-md-3 col-form-label" for="yadif_filter"><?= $language::get('enable_deinterlace_filter'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('deinterlace_video_using_yadif_filter_tooltip'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="yadif_filter" name="yadif_filter" value="1" <?= ($rEdit && ($rProfileOptions[17]['val'] ?? 0) == 1) ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="video_bitrate"><?= $language::get('average_video_bitrate'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_3'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="video_bitrate" name="video_bitrate" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[3]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <label class="col-md-3 col-form-label" for="audio_bitrate"><?= $language::get('average_audio_bitrate'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_4'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="audio_bitrate" name="audio_bitrate" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[4]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="min_tolerance"><?= $language::get('minimum_bitrate_tolerance'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_5'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="min_tolerance" name="min_tolerance" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[5]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <label class="col-md-3 col-form-label" for="max_tolerance"><?= $language::get('maximum_bitrate_tolerance'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_6'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="max_tolerance" name="max_tolerance" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[6]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="buffer_size"><?= $language::get('buffer_size'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_7'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="buffer_size" name="buffer_size" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[7]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <label class="col-md-3 col-form-label" for="crf_value"><?= $language::get('crf_value'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_8'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="crf_value" name="crf_value" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[8]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="framerate"><?= $language::get('target_framerate'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_11'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="framerate" name="framerate" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[11]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <label class="col-md-3 col-form-label" for="samplerate"><?= $language::get('audio_sample_rate'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_12'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="samplerate" name="samplerate" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[12]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="audio_channels"><?= $language::get('audio_channels'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_13'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="audio_channels" name="audio_channels" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[13]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <label class="col-md-3 col-form-label" for="threads"><?= $language::get('threads'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_14'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="threads" name="threads" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[15]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="aspect_ratio"><?= $language::get('aspect_ratio'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_10'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-3"><input type="text" class="form-control" id="aspect_ratio" name="aspect_ratio" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[10]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                </div>
                <div class="row mb-1">
                    <label class="col-md-3 col-form-label" for="logo_path"><?= $language::get('logo_path_url'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('profile_tooltip_16'), ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-6"><input type="text" class="form-control" id="logo_path" name="logo_path" value="<?= $rEdit ? htmlspecialchars((string) ($rProfileOptions[16]['val'] ?? ''), ENT_QUOTES) : ''; ?>"></div>
                    <div class="col-md-3"><input type="text" class="form-control text-center" id="logo_pos" name="logo_pos" value="<?= $rEdit ? htmlspecialchars((string) (($rProfileOptions[16]['pos'] ?? '') ?: '10:10'), ENT_QUOTES) : '10:10'; ?>" placeholder="<?= htmlspecialchars((string) $language::get('pos_xx'), ENT_QUOTES); ?>"></div>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" id="submit_button" class="btn btn-primary" name="submit_profile" value="1"><?= $rEdit ? $language::get('edit') : $language::get('add'); ?></button>
            </div>
        </form>
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
        var toast = window.xcToast || function() {};

        // select2 dropdowns (jQuery so codec toggles keep firing 'change').
        if ($.fn.select2) {
            $('#profile-form select').select2({ width: '100%' });
        }

        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { new bootstrap.Tooltip(el); });
        }

        // Digits-only input filter (reimplements the legacy inputFilter helper).
        var numericOnly = function(id) {
            var el = document.getElementById(id);
            if (!el) { return; }
            el.addEventListener('input', function() { this.value = this.value.replace(/[^\d]/g, ''); });
        };
        ['video_bitrate', 'audio_bitrate', 'min_tolerance', 'max_tolerance', 'buffer_size', 'framerate', 'samplerate', 'audio_channels', 'threads', 'crf_value'].forEach(numericOnly);

        // CPU/GPU pipeline visibility.
        $('#gpu_device').on('change', function() {
            if ($(this).val() == 0) {
                $('#video_codec_cpu_container').show();
                $('#video_codec_gpu_container').hide();
                $('#video_decoding_container').hide();
                $('#gpu_options').hide();
                $('#cpu_options').show();
                $('#gpu_hevc').hide();
                $('#gpu_h264').hide();
            } else {
                $('#video_codec_cpu_container').hide();
                $('#video_codec_gpu_container').show();
                $('#video_decoding_container').show();
                $('#gpu_options').show();
                $('#cpu_options').hide();
                $('#video_codec_gpu').trigger('change');
            }
        });

        // GPU codec picks the h264 vs hevc option block.
        $('#video_codec_gpu').on('change', function() {
            if ($('#gpu_device').val() != 0) {
                if ($(this).val() == 'h264_nvenc') {
                    $('#gpu_hevc').hide();
                    $('#gpu_h264').show();
                } else {
                    $('#gpu_hevc').show();
                    $('#gpu_h264').hide();
                }
            }
        });

        $('#gpu_device').trigger('change');
        $('#video_codec_gpu').trigger('change');

        var form = document.getElementById('profile-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            var fd = new FormData(form);
            fetch('post.php?action=profile&referer=', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (d && d.location) { window.location = d.location; return; }
                    if (btn) { btn.disabled = false; }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                })
                .catch(function() {
                    if (btn) { btn.disabled = false; }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                });
        });

        <?php if (SettingsManager::get('enable_search')): ?>
        if (typeof initSearch === 'function') { initSearch(); }
        <?php endif; ?>
    })();
</script>
</body>

</html>
