<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\TranscodeProfileSavedEvent;
use XcVm\Core\Validation\InputValidator;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ProfileService — profile service
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProfileService {
	use DatabaseAware;

	/**
	 * Create or update a transcode profile from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();
		if (InputValidator::validate('processProfile', $rData)) {
			$rArray = ['profile_name' => $rData['profile_name'], 'profile_options' => null];
			$rProfileOptions = [];

			if ($rData['gpu_device'] != 0) {
				$rProfileOptions['software_decoding'] = (intval($rData['software_decoding']));
				$rProfileOptions['gpu'] = ['val' => $rData['gpu_device'], 'cmd' => ''];
				$rProfileOptions['gpu']['device'] = intval(explode('_', $rData['gpu_device'])[1]);

				if (!$rData['software_decoding']) {
					$rCommand = [];
					$rCommand[] = '-hwaccel cuvid';
					$rCommand[] = '-hwaccel_device ' . $rProfileOptions['gpu']['device'];

					if ((string) $rData['resize'] !== '') {
						$rProfileOptions['gpu']['resize'] = $rData['resize'];
						$rCommand[] = '-resize ' . self::quoted($rData['resize']);
					}

					if (0 < $rData['deint']) {
						$rProfileOptions['gpu']['deint'] = intval($rData['deint']);
						$rCommand[] = '-deint ' . intval($rData['deint']);
					}

					$rCodec = '';

					if ((string) $rData['video_codec_gpu'] !== '') {
						$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_gpu']);
						$rCommand[] = '{INPUT_CODEC}';
						switch ($rData['video_codec_gpu']) {
							case 'hevc_nvenc':
								$rCodec = 'hevc';
								break;
							default:
								$rCodec = 'h264';
								break;
						}
					}

					if ((string) $rData['preset_' . $rCodec] !== '') {
						$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_' . $rCodec]);
					}

					if ((string) $rData['video_profile_' . $rCodec] !== '') {
						$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_' . $rCodec]);
					}

					$rCommand[] = '-gpu ' . $rProfileOptions['gpu']['device'];
					$rCommand[] = '-drop_second_field 1';
					$rProfileOptions['gpu']['cmd'] = implode(' ', $rCommand);
				} else {
					$rCodec = '';

					if ((string) $rData['video_codec_gpu'] !== '') {
						$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_gpu']);
						$rCodec = ($rData['video_codec_gpu'] === 'hevc_nvenc' ? 'hevc' : 'h264');
					}

					if ((string) $rData['preset_' . $rCodec] !== '') {
						$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_' . $rCodec]);
					}

					if ((string) $rData['video_profile_' . $rCodec] !== '') {
						$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_' . $rCodec]);
					}
				}
			} else {
				if ((string) $rData['video_codec_cpu'] !== '') {
					$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_cpu']);
				}

				if ((string) $rData['preset_cpu'] !== '') {
					$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_cpu']);
				}

				if ((string) $rData['video_profile_cpu'] !== '') {
					$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_cpu']);
				}
			}

			if ((string) $rData['audio_codec'] !== '') {
				$rProfileOptions['-acodec'] = escapeshellcmd($rData['audio_codec']);
			}

			if ((string) $rData['video_bitrate'] !== '') {
				$rProfileOptions[3] = ['cmd' => '-b:v ' . intval($rData['video_bitrate']) . 'k', 'val' => intval($rData['video_bitrate'])];
			}

			if ((string) $rData['audio_bitrate'] !== '') {
				$rProfileOptions[4] = ['cmd' => '-b:a ' . intval($rData['audio_bitrate']) . 'k', 'val' => intval($rData['audio_bitrate'])];
			}

			if ((string) $rData['min_tolerance'] !== '') {
				$rProfileOptions[5] = ['cmd' => '-minrate ' . intval($rData['min_tolerance']) . 'k', 'val' => intval($rData['min_tolerance'])];
			}

			if ((string) $rData['max_tolerance'] !== '') {
				$rProfileOptions[6] = ['cmd' => '-maxrate ' . intval($rData['max_tolerance']) . 'k', 'val' => intval($rData['max_tolerance'])];
			}

			if ((string) $rData['buffer_size'] !== '') {
				$rProfileOptions[7] = ['cmd' => '-bufsize ' . intval($rData['buffer_size']) . 'k', 'val' => intval($rData['buffer_size'])];
			}

			if ((string) $rData['crf_value'] !== '') {
				$rProfileOptions[8] = ['cmd' => '-crf ' . intval($rData['crf_value']), 'val' => $rData['crf_value']];
			}

			if ((string) $rData['aspect_ratio'] !== '') {
				$rProfileOptions[10] = ['cmd' => '-aspect ' . self::quoted($rData['aspect_ratio']), 'val' => $rData['aspect_ratio']];
			}

			if ((string) $rData['framerate'] !== '') {
				$rProfileOptions[11] = ['cmd' => '-r ' . intval($rData['framerate']), 'val' => intval($rData['framerate'])];
			}

			if ((string) $rData['samplerate'] !== '') {
				$rProfileOptions[12] = ['cmd' => '-ar ' . intval($rData['samplerate']), 'val' => intval($rData['samplerate'])];
			}

			if ((string) $rData['audio_channels'] !== '') {
				$rProfileOptions[13] = ['cmd' => '-ac ' . intval($rData['audio_channels']), 'val' => intval($rData['audio_channels'])];
			}

			if ((string) $rData['threads'] !== '') {
				$rProfileOptions[15] = ['cmd' => '-threads ' . intval($rData['threads']), 'val' => intval($rData['threads'])];
			}

			$rComplex = false;
			$rScale = $rOverlay = $rLogoInput = '';

			if ((string) $rData['logo_path'] !== '') {
				$rComplex = true;
				$rPos = array_map('intval', explode(':', $rData['logo_pos']));
				if (count($rPos) != 2) {
					$rPos = [10, 10];
				}
				$rLogoInput = '-i ' . escapeshellarg($rData['logo_path']);
				$rProfileOptions[16] = ['cmd' => '', 'val' => $rData['logo_path'], 'pos' => implode(':', $rPos)];
				if ($rData['gpu_device'] != 0 && !$rData['software_decoding']) {
					$rOverlay = '[0:v]hwdownload,format=nv12 [base]; [base][1:v] overlay=' . $rPos[0] . ':' . $rPos[1];
				} else {
					$rOverlay = 'overlay=' . $rPos[0] . ':' . $rPos[1];
				}
			}

			if ($rData['gpu_device'] == 0) {
				if (isset($rData['yadif_filter']) && (string) $rData['scaling'] !== '') {
					$rComplex = true;
				}

				if ($rComplex) {
					if (isset($rData['yadif_filter']) && (string) $rData['scaling'] !== '') {
						if (!$rData['software_decoding']) {
							$rScale = '[0:v]yadif,scale=' . escapeshellcmd($rData['scaling']) . '[bg];[bg][1:v]';
						} else {
							$rScale = 'yadif,scale=' . escapeshellcmd($rData['scaling']);
						}

						$rProfileOptions[9] = ['cmd' => '', 'val' => $rData['scaling']];
						$rProfileOptions[17] = ['cmd' => '', 'val' => 1];
					} else {
						if ((string) $rData['scaling'] !== '') {
							$rScale = 'scale=' . escapeshellcmd($rData['scaling']);
							$rProfileOptions[9] = ['cmd' => '', 'val' => $rData['scaling']];
						} else {
							if (isset($rData['yadif_filter'])) {
								if (!$rData['software_decoding']) {
									$rScale = '[0:v]yadif[bg];[bg][1:v]';
								} else {
									$rScale = 'yadif';
								}
								$rProfileOptions[17] = ['cmd' => '', 'val' => 1];
							}
						}
					}
				} else {
					if ((string) $rData['scaling'] !== '') {
						$rProfileOptions[9] = ['cmd' => '-vf scale=' . self::quoted($rData['scaling']), 'val' => $rData['scaling']];
					}

					if (isset($rData['yadif_filter'])) {
						$rProfileOptions[17] = ['cmd' => '-vf yadif', 'val' => 1];
					}
				}
			} else {
				if (0 < intval($rData['deint']) && (string) $rData['resize'] !== '') {
					$rComplex = true;
				}

				if ($rComplex) {
					if (0 < intval($rData['deint']) && (string) $rData['resize'] !== '') {
						if (!$rData['software_decoding']) {
							$rScale = '[0:v]yadif,scale=' . escapeshellcmd($rData['resize']) . '[bg];[bg][1:v]';
						} else {
							$rScale = 'yadif,scale=' . escapeshellcmd($rData['resize']);
						}

						$rProfileOptions[9] = ['cmd' => '', 'val' => $rData['resize']];
						$rProfileOptions[17] = ['cmd' => '', 'val' => 1];
					} else {
						if ((string) $rData['resize'] !== '') {
							if (!$rData['software_decoding']) {
								$rScale = '[0:v]scale=' . escapeshellcmd($rData['resize']) . '[bg];[bg][1:v]';
							} else {
								$rScale = 'scale=' . escapeshellcmd($rData['resize']);
							}

							$rProfileOptions[9] = ['cmd' => '', 'val' => $rData['resize']];
						} else {
							if (0 < intval($rData['deint'])) {
								if (!$rData['software_decoding']) {
									$rScale = '[0:v]yadif[bg];[bg][1:v]';
								} else {
									$rScale = 'yadif';
								}
								$rProfileOptions[17] = ['cmd' => '', 'val' => 1];
							}
						}
					}
				} else {
					if ((string) $rData['resize'] !== '') {
						$rProfileOptions[9] = ['cmd' => '-vf scale=' . self::quoted($rData['resize']), 'val' => $rData['resize']];
					}

					if (0 < intval($rData['deint'])) {
						$rProfileOptions[17] = ['cmd' => '-vf yadif', 'val' => 1];
					}
				}
			}

			if ($rComplex && !isset($rProfileOptions[16])) {
				// Deinterlace and scaling with no logo: one filter chain on the source, no logo entry.
				$rProfileOptions[9]['cmd'] = '-vf ' . self::quoted('yadif,scale=' . $rProfileOptions[9]['val']);
			} elseif ($rComplex) {
				if (!empty($rScale) && substr($rScale, strlen($rScale) - 1, 1) != ']') {
					$rOverlay = ',' . $rOverlay;
				} else {
					if (!empty($rScale)) {
						$rOverlay = ' ' . $rOverlay;
					}
				}
				$rProfileOptions[16]['cmd'] = str_replace(['{SCALE}', '{OVERLAY}', '{LOGO}'], [$rScale, $rOverlay, $rLogoInput], '{LOGO} -filter_complex "{SCALE}{OVERLAY}"');
			}

			// A codec, a preset and a video profile follow their option on the command line as they
			// are stored, and are read back as plain text: each is a name, a video profile with its level.
			foreach (['-vcodec', '-acodec', '-preset', '-profile:v'] as $rOption) {
				if (isset($rProfileOptions[$rOption]) && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*' . ($rOption === '-profile:v' ? '( -level [0-9.]+)?' : '') . '$/D', $rProfileOptions[$rOption])) {
					return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
				}
			}

			$rArray['profile_options'] = json_encode($rProfileOptions, JSON_UNESCAPED_UNICODE);

			if (isset($rData['edit'])) {
				$rArray['profile_id'] = $rData['edit'];
			}

			$rPrepare = QueryHelper::prepareArray($rArray);
			$rQuery = 'REPLACE INTO `profiles`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				EventDispatcher::dispatch(new TranscodeProfileSavedEvent((int) ($rArray['profile_id'] ?? $rInsertID)));
				return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
			}

			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}

		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * A free-text value of the form, quoted for the command line its option
	 * joins: one argument, as typed. In double quotes, with those of the
	 * value escaped: StreamUtils::parseTranscode() gathers every
	 * -filter_complex "..." it finds in an option's text, and a value quoted
	 * this way cannot hold one.
	 *
	 * @param string $rValue The value as typed.
	 * @return string
	 */
	private static function quoted(string $rValue): string {
		return '"' . addcslashes($rValue, '"$`\\') . '"';
	}
}
