<?php

/**
 * Time Utilities
 *
 * Time formatting and conversion helpers.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TimeUtils {

    /**
     * Get timezone difference in seconds between server timezone and user timezone
     *
     * @param string $timezone User timezone identifier (e.g., 'Europe/Moscow')
     * @return int Offset in seconds
     */
    public static function getDiffTimezone($timezone) {
        $serverTZ = new DateTime('UTC', new DateTimeZone(date_default_timezone_get()));
        $userTZ = new DateTime('UTC', new DateTimeZone($timezone));
        return $userTZ->getTimestamp() - $serverTZ->getTimestamp();
    }

    /**
     * Convert seconds to human-readable time string
     *
     * Examples:
     *   3661  → "1h 1m 1s"
     *   86400 → "1d"
     *   90    → "1m 30s"
     *
     * @param int $inputSeconds Total seconds
     * @param bool $includeSecs Whether to include seconds in output
     * @return string Formatted time string
     */
    public static function secondsToTime($inputSeconds, $includeSecs = true) {
        $secondsInMinute = 60;
        $secondsInHour = 3600;
        $secondsInDay = 86400;

        $days = (int)floor($inputSeconds / $secondsInDay);
        $hourSeconds = $inputSeconds % $secondsInDay;
        $hours = (int)floor($hourSeconds / $secondsInHour);
        $minuteSeconds = $hourSeconds % $secondsInHour;
        $minutes = (int)floor($minuteSeconds / $secondsInMinute);
        $remaining = $minuteSeconds % $secondsInMinute;
        $seconds = (int)ceil($remaining);

        $output = '';

        if ($days > 0) {
            $output .= $days . 'd ';
        }
        if ($hours > 0) {
            $output .= $hours . 'h ';
        }
        if ($minutes > 0) {
            $output .= $minutes . 'm ';
        }
        if ($includeSecs) {
            $output .= $seconds . 's';
        }

        return trim($output);
    }

    /**
     * Parse a duration string "HH:MM:SS" into total seconds
     *
     * @param string $duration "HH:MM:SS" or "MM:SS"
     * @return int Total seconds
     */
    public static function durationToSeconds($duration) {
        $parts = explode(':', $duration);
        $count = count($parts);

        if ($count === 3) {
            return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        }
        if ($count === 2) {
            return (int)$parts[0] * 60 + (int)$parts[1];
        }

        return (int)$duration;
    }

    /**
     * Get a human-readable "time ago" string
     *
     * @param int $timestamp Unix timestamp
     * @return string e.g., "5m ago", "2h ago", "1d ago"
     */
    public static function timeAgo($timestamp) {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return (int)($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return (int)($diff / 3600) . 'h ago';
        }

        return (int)($diff / 86400) . 'd ago';
    }

    /**
     * Get current time as formatted string
     *
     * @param string $format PHP date format (default: 'Y-m-d H:i:s')
     * @return string
     */
    public static function now($format = 'Y-m-d H:i:s') {
        return date($format);
    }
}
