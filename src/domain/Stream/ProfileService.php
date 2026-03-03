<?php

class ProfileService {
	public static function process($rData) {
		global $db;
		if (InputValidator::validate('processProfile', $rData)) {
			$rArray = array('profile_name' => $rData['profile_name'], 'profile_options' => null);
			$rProfileOptions = array();

			if ($rData['gpu_device'] != 0) {
				$rProfileOptions['software_decoding'] = (intval($rData['software_decoding']) ?: 0);
				$rProfileOptions['gpu'] = array('val' => $rData['gpu_device'], 'cmd' => '');
				$rProfileOptions['gpu']['device'] = intval(explode('_', $rData['gpu_device'])[1]);

				if (!$rData['software_decoding']) {
					$rCommand = array();
					$rCommand[] = '-hwaccel cuvid';
					$rCommand[] = '-hwaccel_device ' . $rProfileOptions['gpu']['device'];

					if (0 >= strlen($rData['resize'])) {
					} else {
						$rProfileOptions['gpu']['resize'] = $rData['resize'];
						$rCommand[] = '-resize ' . escapeshellcmd($rData['resize']);
					}

					if (0 >= $rData['deint']) {
					} else {
						$rProfileOptions['gpu']['deint'] = intval($rData['deint']);
						$rCommand[] = '-deint ' . intval($rData['deint']);
					}

					$rCodec = '';

					if (0 >= strlen($rData['video_codec_gpu'])) {
					} else {
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

					if (0 >= strlen($rData['preset_' . $rCodec])) {
					} else {
						$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_' . $rCodec]);
					}

					if (0 >= strlen($rData['video_profile_' . $rCodec])) {
					} else {
						$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_' . $rCodec]);
					}

					$rCommand[] = '-gpu ' . $rProfileOptions['gpu']['device'];
					$rCommand[] = '-drop_second_field 1';
					$rProfileOptions['gpu']['cmd'] = implode(' ', $rCommand);
				} else {
					$rCodec = '';

					if (0 >= strlen($rData['video_codec_gpu'])) {
					} else {
						$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_gpu']);

						switch ($rData['video_codec_gpu']) {
							case 'hevc_nvenc':
								$rCodec = 'hevc';
								break;
						}
						$rCodec = 'h264';
					}

					if (0 >= strlen($rData['preset_' . $rCodec])) {
					} else {
						$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_' . $rCodec]);
					}

					if (0 >= strlen($rData['video_profile_' . $rCodec])) {
					} else {
						$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_' . $rCodec]);
					}
				}
			} else {
				if (0 >= strlen($rData['video_codec_cpu'])) {
				} else {
					$rProfileOptions['-vcodec'] = escapeshellcmd($rData['video_codec_cpu']);
				}

				if (0 >= strlen($rData['preset_cpu'])) {
				} else {
					$rProfileOptions['-preset'] = escapeshellcmd($rData['preset_cpu']);
				}

				if (0 >= strlen($rData['video_profile_cpu'])) {
				} else {
					$rProfileOptions['-profile:v'] = escapeshellcmd($rData['video_profile_cpu']);
				}
			}

			if (0 >= strlen($rData['audio_codec'])) {
			} else {
				$rProfileOptions['-acodec'] = escapeshellcmd($rData['audio_codec']);
			}

			if (0 >= strlen($rData['video_bitrate'])) {
			} else {
				$rProfileOptions[3] = array('cmd' => '-b:v ' . intval($rData['video_bitrate']) . 'k', 'val' => intval($rData['video_bitrate']));
			}

			if (0 >= strlen($rData['audio_bitrate'])) {
			} else {
				$rProfileOptions[4] = array('cmd' => '-b:a ' . intval($rData['audio_bitrate']) . 'k', 'val' => intval($rData['audio_bitrate']));
			}

			if (0 >= strlen($rData['min_tolerance'])) {
			} else {
				$rProfileOptions[5] = array('cmd' => '-minrate ' . intval($rData['min_tolerance']) . 'k', 'val' => intval($rData['min_tolerance']));
			}

			if (0 >= strlen($rData['max_tolerance'])) {
			} else {
				$rProfileOptions[6] = array('cmd' => '-maxrate ' . intval($rData['max_tolerance']) . 'k', 'val' => intval($rData['max_tolerance']));
			}

			if (0 >= strlen($rData['buffer_size'])) {
			} else {
				$rProfileOptions[7] = array('cmd' => '-bufsize ' . intval($rData['buffer_size']) . 'k', 'val' => intval($rData['buffer_size']));
			}

			if (0 >= strlen($rData['crf_value'])) {
			} else {
				$rProfileOptions[8] = array('cmd' => '-crf ' . intval($rData['crf_value']), 'val' => $rData['crf_value']);
			}

			if (0 >= strlen($rData['aspect_ratio'])) {
			} else {
				$rProfileOptions[10] = array('cmd' => '-aspect ' . escapeshellcmd($rData['aspect_ratio']), 'val' => $rData['aspect_ratio']);
			}

			if (0 >= strlen($rData['framerate'])) {
			} else {
				$rProfileOptions[11] = array('cmd' => '-r ' . intval($rData['framerate']), 'val' => intval($rData['framerate']));
			}

			if (0 >= strlen($rData['samplerate'])) {
			} else {
				$rProfileOptions[12] = array('cmd' => '-ar ' . intval($rData['samplerate']), 'val' => intval($rData['samplerate']));
			}

			if (0 >= strlen($rData['audio_channels'])) {
			} else {
				$rProfileOptions[13] = array('cmd' => '-ac ' . intval($rData['audio_channels']), 'val' => intval($rData['audio_channels']));
			}

			if (0 >= strlen($rData['threads'])) {
			} else {
				$rProfileOptions[15] = array('cmd' => '-threads ' . intval($rData['threads']), 'val' => intval($rData['threads']));
			}

			$rComplex = false;
			$rScale = $rOverlay = $rLogoInput = '';

			if (0 >= strlen($rData['logo_path'])) {
			} else {
				$rComplex = true;
				$rPos = array_map('intval', explode(':', $rData['logo_pos']));

				if (count($rPos) == 2) {
				} else {
					$rPos = array(10, 10);
				}

				$rLogoInput = '-i ' . escapeshellarg($rData['logo_path']);
				$rProfileOptions[16] = array('cmd' => '', 'val' => $rData['logo_path'], 'pos' => implode(':', $rPos));

				if ($rData['gpu_device'] != 0 && !$rData['software_decoding']) {
					$rOverlay = '[0:v]hwdownload,format=nv12 [base]; [base][1:v] overlay=' . $rPos[0] . ':' . $rPos[1];
				} else {
					$rOverlay = 'overlay=' . $rPos[0] . ':' . $rPos[1];
				}
			}

			if ($rData['gpu_device'] == 0) {
				if (!(isset($rData['yadif_filter']) && 0 < strlen($rData['scaling']))) {
				} else {
					$rComplex = true;
				}

				if ($rComplex) {
					if (isset($rData['yadif_filter']) && 0 < strlen($rData['scaling'])) {
						if (!$rData['software_decoding']) {
							$rScale = '[0:v]yadif,scale=' . escapeshellcmd($rData['scaling']) . '[bg];[bg][1:v]';
						} else {
							$rScale = 'yadif,scale=' . escapeshellcmd($rData['scaling']);
						}

						$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['scaling']);
						$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
					} else {
						if (0 < strlen($rData['scaling'])) {
							$rScale = 'scale=' . escapeshellcmd($rData['scaling']);
							$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['scaling']);
						} else {
							if (!isset($rData['yadif_filter'])) {
							} else {
								if (!$rData['software_decoding']) {
									$rScale = '[0:v]yadif[bg];[bg][1:v]';
								} else {
									$rScale = 'yadif';
								}

								$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
							}
						}
					}
				} else {
					if (0 >= strlen($rData['scaling'])) {
					} else {
						$rProfileOptions[9] = array('cmd' => '-vf scale=' . escapeshellcmd($rData['scaling']), 'val' => $rData['scaling']);
					}

					if (!isset($rData['yadif_filter'])) {
					} else {
						$rProfileOptions[17] = array('cmd' => '-vf yadif', 'val' => 1);
					}
				}
			} else {
				if (!$rData['software_decoding']) {
				} else {
					if (!(0 < intval($rData['deint']) && 0 < strlen($rData['resize']))) {
					} else {
						$rComplex = true;
					}

					if ($rComplex) {
						if (0 < intval($rData['deint']) && 0 < strlen($rData['resize'])) {
							if (!$rData['software_decoding']) {
								$rScale = '[0:v]yadif,scale=' . escapeshellcmd($rData['resize']) . '[bg];[bg][1:v]';
							} else {
								$rScale = 'yadif,scale=' . escapeshellcmd($rData['resize']);
							}

							$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['resize']);
							$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
						} else {
							if (0 < strlen($rData['resize'])) {
								if (!$rData['software_decoding']) {
									$rScale = '[0:v]scale=' . escapeshellcmd($rData['resize']) . '[bg];[bg][1:v]';
								} else {
									$rScale = 'scale=' . escapeshellcmd($rData['resize']);
								}

								$rProfileOptions[9] = array('cmd' => '', 'val' => $rData['resize']);
							} else {
								if (0 >= intval($rData['deint'])) {
								} else {
									if (!$rData['software_decoding']) {
										$rScale = '[0:v]yadif[bg];[bg][1:v]';
									} else {
										$rScale = 'yadif';
									}

									$rProfileOptions[17] = array('cmd' => '', 'val' => 1);
								}
							}
						}
					} else {
						if (0 >= strlen($rData['resize'])) {
						} else {
							$rProfileOptions[9] = array('cmd' => '-vf scale=' . escapeshellcmd($rData['resize']), 'val' => $rData['resize']);
						}

						if (0 >= intval($rData['deint'])) {
						} else {
							$rProfileOptions[17] = array('cmd' => '-vf yadif', 'val' => 1);
						}
					}
				}
			}

			if (!$rComplex) {
			} else {
				if (!empty($rScale) && substr($rScale, strlen($rScale) - 1, 1) != ']') {
					$rOverlay = ',' . $rOverlay;
				} else {
					if (empty($rScale)) {
					} else {
						$rOverlay = ' ' . $rOverlay;
					}
				}

				$rProfileOptions[16]['cmd'] = str_replace(array('{SCALE}', '{OVERLAY}', '{LOGO}'), array($rScale, $rOverlay, $rLogoInput), '{LOGO} -filter_complex "{SCALE}{OVERLAY}"');
			}

			$rArray['profile_options'] = json_encode($rProfileOptions, JSON_UNESCAPED_UNICODE);

			if (!isset($rData['edit'])) {
			} else {
				$rArray['profile_id'] = $rData['edit'];
			}

			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `profiles`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}
}
