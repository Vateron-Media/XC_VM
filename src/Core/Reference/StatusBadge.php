<?php

declare(strict_types=1);

namespace XcVm\Core\Reference;

/**
 * Pre-rendered status badge HTML (and log labels) for admin tables.
 *
 * Replaces the legacy `$rStatusArray`, `$rSearchStatusArray`,
 * `$rVODStatusArray`, `$rWatchStatusArray`, `$rFailureStatusArray` and
 * `$rStreamLogsArray` globals from resources/data/admin_constants.php.
 *
 * The markup is kept verbatim from the legacy globals; each accessor
 * resolves a status key to its badge, returning an empty string for
 * unknown keys (the stream badge falls back to the STOPPED badge, as the
 * legacy TableController code did).
 *
 * @package XC_VM_Core_Reference
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StatusBadge {
    /** Live stream status code => badge HTML. */
    private const STREAM = array(-1 => "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xl'>NO SERVERS</button>", 0 => "<button type='button' class='btn btn-dark btn-xs waves-effect waves-light btn-fixed-xl'>STOPPED</button>", 1 => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light btn-fixed-xl'>ONLINE</button>", 2 => "<button type='button' class='btn btn-warning btn-xs waves-effect waves-light btn-fixed'>STARTING</button>", 3 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light btn-fixed'>DOWN</button>", 4 => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light btn-fixed-xl'>ON DEMAND</button>", 5 => "<button type='button' class='btn btn-purple btn-xs waves-effect waves-light btn-fixed-xl'>DIRECT SOURCE</button>", 6 => "<button type='button' class='btn btn-primary btn-xs waves-effect waves-light btn-fixed-xl'>CREATING...</button>", 7 => "<button type='button' class='btn btn-purple btn-xs waves-effect waves-light btn-fixed-xl'>DIRECT STREAM</button>");

    /** Live-search animated status code => badge HTML (legacy positional keys preserved). */
    private const SEARCH = array(-1 => "<button type='button' class='btn bg-animate-secondary btn-xs waves-effect waves-light no-border btn-fixed-xl'>NO SERVERS</button>", 0 => "<button type='button' class='btn bg-animate-dark btn-xs waves-effect waves-light no-border btn-fixed-xl'>STOPPED</button>", "<button type='button' class='btn bg-animate-warning btn-xs waves-effect waves-light no-border btn-fixed-xl'>STARTING</button>", "<button type='button' class='btn bg-animate-danger btn-xs waves-effect waves-light no-border btn-fixed-xl'>DOWN</button>", "<button type='button' class='btn bg-animate-success btn-xs waves-effect waves-light no-border btn-fixed-xl'>ON DEMAND</button>", "<button type='button' class='btn bg-animate-purple btn-xs waves-effect waves-light no-border btn-fixed-xl'>DIRECT</button>", 7 => "<button type='button' class='btn bg-animate-warning btn-xs waves-effect waves-light no-border btn-fixed-xl'>ENCODING</button>", 8 => "<button type='button' class='btn bg-animate-dark btn-xs waves-effect waves-light no-border btn-fixed-xl'>NOT ENCODED</button>", 9 => "<button type='button' class='btn bg-animate-info btn-xs waves-effect waves-light no-border btn-fixed-xl'>ENCODED</button>", 10 => "<button type='button' class='btn bg-animate-danger btn-xs waves-effect waves-light no-border btn-fixed-xl'>BROKEN</button>");

    /** VOD encode status code => badge HTML. */
    private const VOD = array(-1 => "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light tooltip' title='No Server Selected'><i class='text-white mdi mdi-triangle'></i></button>", 0 => "<button type='button' class='btn btn-dark btn-xs waves-effect waves-light tooltip' title='Not Encoded'><i class='text-white mdi mdi-checkbox-blank-circle'></i></button>", 1 => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light tooltip' title='Encoded'><i class='text-white mdi mdi-check-circle'></i></button>", 2 => "<button type='button' class='btn btn-warning btn-xs waves-effect waves-light tooltip' title='Encoding'><i class='text-white mdi mdi-checkbox-blank-circle'></i></button>", 3 => "<button type='button' class='btn btn-primary btn-xs waves-effect waves-light tooltip' title='Direct Source'><i class='text-white mdi mdi mdi-web'></i></button>", 4 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light tooltip' title='Down'><i class='text-white mdi mdi-triangle'></i></button>", 5 => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light tooltip' title='Direct Stream'><i class='text-white mdi mdi mdi-web'></i></button>");

    /** VOD folder-watch import status code => badge HTML. */
    private const WATCH = array(1 => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light'>ADDED</button>", 2 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>SQL FAILED</button>", 3 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>NO CATEGORY</button>", 4 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>NO TMDb MATCH</button>", 5 => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>INVALID FILE</button>", 6 => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>UPGRADED</button>");

    /** Stream failure/log action => badge HTML. */
    private const FAILURE = array('STREAM_STOP' => "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xl'>STOPPED</button>", 'STREAM_START_FAIL' => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light btn-fixed-xl'>START FAILED</button>", 'STREAM_START' => "<button type='button' class='btn btn-success btn-xs waves-effect waves-light btn-fixed-xl'>STARTED</button>", 'STREAM_RESTART' => "<button type='button' class='btn btn-info btn-xs waves-effect waves-light btn-fixed-xl'>RESTARTED</button>", 'STREAM_FAILED' => "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light btn-fixed-xl'>STREAM FAILED</button>");

    /** Stream-log action => human-readable label. */
    private const STREAM_LOG = array('STREAM_FAILED' => 'Stream Failed', 'STREAM_START' => 'Stream Started', 'STREAM_RESTART' => 'Stream Restarted', 'STREAM_STOP' => 'Stream Stopped', 'FORCE_SOURCE' => 'Force Change Source', 'AUTO_RESTART' => 'Timed Auto Restart', 'AUDIO_LOSS' => 'Audio Lost', 'PRIORITY_SWITCH' => 'Priority Switch', 'DELAY_START' => 'Delay Started', 'FFMPEG_ERROR' => 'FFMPEG Error');

    /**
     * Live stream status badge. Unknown codes fall back to the STOPPED
     * badge, matching the legacy `$rStatusArray[$x] ?? $rStatusArray[0]`.
     */
    public static function stream(int $status): string {
        return self::STREAM[$status] ?? self::STREAM[0];
    }

    /**
     * Live-search animated status badge ('' for unknown codes).
     */
    public static function search(int $status): string {
        return self::SEARCH[$status] ?? '';
    }

    /**
     * VOD encode status badge ('' for unknown codes).
     */
    public static function vod(int $status): string {
        return self::VOD[$status] ?? '';
    }

    /**
     * VOD folder-watch import status badge ('' for unknown codes).
     */
    public static function watch(int $status): string {
        return self::WATCH[$status] ?? '';
    }

    /**
     * Stream failure/log action badge ('' for unknown actions).
     */
    public static function failure(string $action): string {
        return self::FAILURE[$action] ?? '';
    }

    /**
     * Stream-log action label ('' for unknown actions).
     */
    public static function streamLog(string $action): string {
        return self::STREAM_LOG[$action] ?? '';
    }
}
