<?php

namespace XcVm\Module\Telegram;

/**
 * TelegramMessageFormatter — Formats rich, visually stunning messages for Telegram.
 *
 * Employs premium typography, emoji accents, structured HTML markup,
 * intelligent caption truncation (under Telegram's 1024-char caption limit),
 * custom template placeholders, and media URL resolution.
 *
 * @package XC_VM_Module_Telegram
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramMessageFormatter
{
    /**
     * Format a complete movie broadcast message and resolve its image.
     *
     * @param array  $movie           Movie data array from `streams` and `streams_servers`
     * @param string $imagePreference 'poster', 'backdrop', or 'none'
     * @param string|null $customTemplate Optional user-defined template string
     * @param string|null $categoryName Category name if resolved
     * @return array{text: string, image: string}
     */
    public static function formatMovie(array $movie, string $imagePreference = 'poster', ?string $customTemplate = null, ?string $categoryName = null): array
    {
        $props = is_array($movie['movie_properties'] ?? null)
            ? $movie['movie_properties']
            : (json_decode($movie['movie_properties'] ?? '[]', true) ?: []);

        $title = $props['name'] ?? ($props['o_name'] ?? ($movie['stream_display_name'] ?? 'Untitled Movie'));
        $year = $movie['year'] ?? '';
        if ($year === '' && !empty($props['release_date'])) {
            $year = substr($props['release_date'], 0, 4);
        }

        $rating = !empty($props['rating']) ? (string)$props['rating'] : (!empty($movie['rating']) ? (string)$movie['rating'] : '');
        $ratingDisplay = $rating !== '' ? (is_numeric($rating) ? number_format((float)$rating, 1) : $rating) . ' / 10' : '';

        $genre = $props['genre'] ?? '';
        $releaseDate = $props['release_date'] ?? '';

        // Calculate friendly duration
        $duration = '';
        if (!empty($props['duration'])) {
            $parts = explode(':', (string)$props['duration']);
            if (count($parts) >= 2) {
                $h = (int)$parts[0];
                $m = (int)$parts[1];
                $duration = ($h > 0 ? "{$h}h " : '') . "{$m}m";
            } else {
                $duration = (string)$props['duration'];
            }
        } elseif (!empty($props['duration_secs'])) {
            $secs = (int)$props['duration_secs'];
            $h = floor($secs / 3600);
            $m = floor(($secs % 3600) / 60);
            $duration = ($h > 0 ? "{$h}h " : '') . "{$m}m";
        }

        // Resolution & Codecs
        $res = $movie['resolution'] ?? ($props['video']['height'] ?? '');
        $quality = '';
        if ($res) {
            $resInt = (int)$res;
            if ($resInt >= 2160) {
                $quality = '4K UHD (2160p)';
            } elseif ($resInt >= 1440) {
                $quality = '2K QHD (1440p)';
            } elseif ($resInt >= 1080) {
                $quality = 'FHD (1080p)';
            } elseif ($resInt >= 720) {
                $quality = 'HD (720p)';
            } else {
                $quality = "{$resInt}p";
            }
        }

        $vCodec = strtoupper((string)($movie['video_codec'] ?? ($props['video']['codec_name'] ?? '')));
        $aCodec = strtoupper((string)($movie['audio_codec'] ?? ($props['audio']['codec_name'] ?? '')));
        if ($vCodec === 'H264') $vCodec = 'AVC/x264';
        if ($vCodec === 'HEVC' || $vCodec === 'H265') $vCodec = 'HEVC/x265';
        if ($aCodec === 'AAC') $aCodec = 'AAC';

        $plot = trim((string)($props['plot'] ?? ($props['description'] ?? '')));
        $category = $categoryName ?: 'Movies';

        // Resolve Image
        $image = '';
        if ($imagePreference !== 'none') {
            if ($imagePreference === 'backdrop') {
                $backdrops = $props['backdrop_path'] ?? [];
                if (is_array($backdrops) && !empty($backdrops[0])) {
                    $image = self::resolveImagePath((string)$backdrops[0]);
                } elseif (is_string($backdrops) && $backdrops !== '') {
                    $image = self::resolveImagePath($backdrops);
                }
            }

            // Fallback or Primary Poster
            if ($image === '') {
                $poster = $props['movie_image'] ?? ($props['cover_big'] ?? ($movie['stream_icon'] ?? ''));
                if ($poster !== '') {
                    $image = self::resolveImagePath((string)$poster);
                }
            }
        }

        // Custom Template vs Standard Premium Template
        if ($customTemplate !== null && trim($customTemplate) !== '') {
            $replacements = [
                '{title}'        => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                '{year}'         => htmlspecialchars((string)$year, ENT_QUOTES, 'UTF-8'),
                '{rating}'       => htmlspecialchars($ratingDisplay, ENT_QUOTES, 'UTF-8'),
                '{genre}'        => htmlspecialchars($genre, ENT_QUOTES, 'UTF-8'),
                '{genres}'       => htmlspecialchars($genre, ENT_QUOTES, 'UTF-8'),
                '{duration}'     => htmlspecialchars($duration, ENT_QUOTES, 'UTF-8'),
                '{quality}'      => htmlspecialchars($quality, ENT_QUOTES, 'UTF-8'),
                '{video}'        => htmlspecialchars($vCodec, ENT_QUOTES, 'UTF-8'),
                '{audio}'        => htmlspecialchars($aCodec, ENT_QUOTES, 'UTF-8'),
                '{category}'     => htmlspecialchars($category, ENT_QUOTES, 'UTF-8'),
                '{plot}'         => htmlspecialchars($plot, ENT_QUOTES, 'UTF-8'),
                '{release_date}' => htmlspecialchars($releaseDate, ENT_QUOTES, 'UTF-8'),
                '{date}'         => date('Y-m-d'),
            ];
            $text = str_replace(array_keys($replacements), array_values($replacements), $customTemplate);
        } else {
            $eTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $eYear = $year !== '' ? ' (' . htmlspecialchars((string)$year, ENT_QUOTES, 'UTF-8') . ')' : '';

            $lines = [];
            $lines[] = "🎬 <b>{$eTitle}{$eYear}</b>";
            $lines[] = "━━━━━━━━━━━━━━━━━━";

            if ($ratingDisplay !== '') {
                $lines[] = "⭐ <b>Rating:</b> " . htmlspecialchars($ratingDisplay, ENT_QUOTES, 'UTF-8');
            }
            if ($genre !== '') {
                $lines[] = "🎭 <b>Genre:</b> " . htmlspecialchars($genre, ENT_QUOTES, 'UTF-8');
            }
            if ($duration !== '') {
                $lines[] = "⏱ <b>Duration:</b> " . htmlspecialchars($duration, ENT_QUOTES, 'UTF-8');
            }

            $techSpecs = [];
            if ($quality !== '') $techSpecs[] = $quality;
            if ($vCodec !== '' || $aCodec !== '') {
                $codecs = array_filter([$vCodec, $aCodec]);
                $techSpecs[] = implode(' • ', $codecs);
            }
            if (!empty($techSpecs)) {
                $lines[] = "📺 <b>Quality:</b> " . htmlspecialchars(implode(' | ', $techSpecs), ENT_QUOTES, 'UTF-8');
            }

            if ($category !== '') {
                $lines[] = "📁 <b>Category:</b> " . htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
            }

            if ($plot !== '') {
                // Ensure overall text stays safely under 1024 characters
                $maxPlotLen = 320;
                $trimmedPlot = mb_strlen($plot) > $maxPlotLen
                    ? mb_substr($plot, 0, $maxPlotLen) . '...'
                    : $plot;
                $lines[] = "";
                $lines[] = "📝 <b>Overview:</b>\n" . htmlspecialchars($trimmedPlot, ENT_QUOTES, 'UTF-8');
            }

            $lines[] = "";
            $lines[] = "✨ <i>Now downloaded & ready to stream in highest quality!</i>";

            $text = implode("\n", $lines);
        }

        // Safety cap for Telegram captions (Telegram maximum is 1024)
        if (mb_strlen($text) > 1020) {
            $text = mb_substr($text, 0, 1017) . '...';
        }

        return [
            'text'  => $text,
            'image' => $image,
        ];
    }

    /**
     * Format a series episode notification message.
     *
     * @param array $episode Episode stream record
     * @param array $series  Parent series record
     * @param string|null $categoryName
     * @return array{text: string, image: string}
     */
    public static function formatEpisode(array $episode, array $series, ?string $categoryName = null): array
    {
        $seriesTitle = $series['title'] ?? 'TV Series';
        $episodeName = $episode['stream_display_name'] ?? 'New Episode';
        $category = $categoryName ?: 'TV Series';

        $eSeries = htmlspecialchars($seriesTitle, ENT_QUOTES, 'UTF-8');
        $eEp = htmlspecialchars($episodeName, ENT_QUOTES, 'UTF-8');
        $eCat = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');

        $lines = [];
        $lines[] = "📺 <b>{$eSeries}</b>";
        $lines[] = "🏷 <b>Episode:</b> {$eEp}";
        $lines[] = "📁 <b>Category:</b> {$eCat}";

        if (!empty($series['rating'])) {
            $lines[] = "⭐ <b>Rating:</b> " . htmlspecialchars((string)$series['rating'], ENT_QUOTES, 'UTF-8') . " / 10";
        }
        if (!empty($series['genre'])) {
            $lines[] = "🎭 <b>Genre:</b> " . htmlspecialchars((string)$series['genre'], ENT_QUOTES, 'UTF-8');
        }

        $lines[] = "";
        $lines[] = "✨ <i>New episode is now ready to stream!</i>";

        $image = '';
        if (!empty($series['cover'])) {
            $image = self::resolveImagePath((string)$series['cover']);
        } elseif (!empty($series['backdrop_path'])) {
            $image = self::resolveImagePath((string)$series['backdrop_path']);
        }

        return [
            'text'  => implode("\n", $lines),
            'image' => $image,
        ];
    }

    /**
     * Format a live stream announcement message.
     *
     * @param array $stream Live stream record
     * @param string|null $categoryName
     * @return array{text: string, image: string}
     */
    public static function formatLiveStream(array $stream, ?string $categoryName = null): array
    {
        $title = $stream['stream_display_name'] ?? 'Live Channel';
        $category = $categoryName ?: 'Live TV';

        $eTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $eCat = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');

        $lines = [];
        $lines[] = "📡 <b>New Live Channel Online!</b>";
        $lines[] = "━━━━━━━━━━━━━━━━━━";
        $lines[] = "📺 <b>Channel:</b> {$eTitle}";
        $lines[] = "📁 <b>Category:</b> {$eCat}";
        $lines[] = "⏱ <b>Added:</b> " . date('Y-m-d H:i');
        $lines[] = "";
        $lines[] = "✨ <i>Live stream is now on air and available for viewers!</i>";

        $image = '';
        if (!empty($stream['stream_icon'])) {
            $image = self::resolveImagePath((string)$stream['stream_icon']);
        }

        return [
            'text'  => implode("\n", $lines),
            'image' => $image,
        ];
    }

    /**
     * Format a test ping message.
     *
     * @param string $botName
     * @return string
     */
    public static function formatTestPing(string $botName): string
    {
        $eName = htmlspecialchars($botName, ENT_QUOTES, 'UTF-8');
        $date = date('Y-m-d H:i:s');

        return "🤖 <b>Telegram Bot Test: {$eName}</b>\n"
             . "━━━━━━━━━━━━━━━━━━\n"
             . "✅ <b>Connection Status:</b> Successfully Connected!\n"
             . "📡 <b>Permissions:</b> Message Posting Verified\n"
             . "⏱ <b>Timestamp:</b> {$date}\n\n"
             . "🎉 <i>Your bot is properly configured and ready to broadcast new content.</i>";
    }

    /**
     * Resolve image URL or local file path from system representation.
     *
     * @param string $raw
     * @return string
     */
    public static function resolveImagePath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Direct remote HTTP URL
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        // Internal server notation: s:1:/images/xyz.jpg
        if (str_starts_with($raw, 's:')) {
            $parts = explode(':', $raw, 3);
            if (!empty($parts[2])) {
                $base = basename($parts[2]);
                if (defined('IMAGES_PATH') && file_exists(IMAGES_PATH . $base)) {
                    return IMAGES_PATH . $base;
                }
            }
        }

        // Local filename or path
        if (defined('IMAGES_PATH') && file_exists(IMAGES_PATH . basename($raw))) {
            return IMAGES_PATH . basename($raw);
        }

        if (file_exists($raw)) {
            return $raw;
        }

        return '';
    }
}
