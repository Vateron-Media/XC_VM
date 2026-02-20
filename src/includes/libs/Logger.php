<?php

/**
 * A centralized logger class for handling PHP errors, exceptions, and fatal errors.
 * Logs all events to a file in base64-encoded JSON format and optionally displays them on screen in development mode.
 */
final class Logger {
    /** @var bool Whether development mode is enabled (errors are displayed on screen) */
    private static bool $development = false;

    /** @var string Full path to the log file */
    private static string $logFile;

    /**
     * Initializes error, exception, and fatal error handlers.
     *
     * @param bool   $showErrors true for development mode (display errors), false for production
     * @param string $logFile    Full path to the file where logs will be written
     */
    public static function init(bool $showErrors, string $logFile): void {
        self::$development = $showErrors;
        self::$logFile     = $logFile;

        // Set custom handlers
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleFatal']);

        if ($showErrors) {
            // In development mode, show all errors
            error_reporting(E_ERROR | E_WARNING);
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        } else {
            // In production, hide errors from the user
            error_reporting(0);
            ini_set('display_errors', '0');
        }
    }

    /* ================= ERRORS ================= */

    /**
     * Handler for regular PHP errors (trigger_error, warnings, notices, etc.).
     *
     * @param int    $errno   Error level (E_WARNING, E_NOTICE, etc.)
     * @param string $message Error message
     * @param string $file    File where the error occurred
     * @param int    $line    Line number in the file
     *
     * @return bool true if the error was handled (prevents default PHP handler)
     */
    public static function handleError(
        int $errno,
        string $message,
        string $file,
        int $line
    ): bool {
        // Ignore suppressed errors (using @ operator)
        if (!(error_reporting() & $errno)) {
            return false;
        }

        self::log(
            self::mapErrorLevel($errno),
            $message,
            self::buildTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)),
            $file,
            $line
        );

        return true;
    }

    /* ================= EXCEPTIONS ================= */

    /**
     * Handler for uncaught exceptions.
     *
     * @param Throwable $e The uncaught exception
     */
    public static function handleException(Throwable $e): void {
        self::log(
            'EXCEPTION',
            $e->getMessage(),
            self::buildExceptionTrace($e),
            $e->getFile(),
            $e->getLine()
        );
    }

    /* ================= FATAL ================= */

    /**
     * Handler for fatal errors (E_ERROR, E_PARSE, etc.) that terminate script execution.
     * Called via register_shutdown_function.
     */
    public static function handleFatal(): void {
        $error = error_get_last();

        // Process only true fatal errors
        if ($error && in_array($error['type'], [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR
        ], true)) {
            self::log(
                'FATAL',
                $error['message'],
                '', // Stack trace is not available for fatal errors
                $error['file'],
                $error['line']
            );
        }
    }

    /* ================= CORE LOG ================= */

    /**
     * Core logging method. Writes the event to the log file and optionally outputs it in development mode.
     *
     * @param string $type    Event type (ERROR, WARNING, NOTICE, EXCEPTION, FATAL, etc.)
     * @param string $message Message text
     * @param string $trace   Stack trace (optional)
     * @param string $file    File where the event occurred
     * @param int    $line    Line number
     */
    public static function log(
        string $type,
        string $message,
        string $trace = '',
        string $file = '',
        int $line = 0
    ): void {
        $data = [
            'type'    => $type,
            'log_message' => $message,
            'file'    => $file,
            'line'    => $line,
            'log_extra'   => $trace,            // Process ID
            'time'    => time(),                  // Unix timestamp
            'env'     => php_sapi_name()          // SAPI name (cli, fpm-fcgi, etc.)
        ];

        // Write to file as base64-encoded JSON (easy to parse and prevents line corruption)
        file_put_contents(
            self::$logFile,
            base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . "\n",
            FILE_APPEND | LOCK_EX
        );

        // Set log file permissions if running as root (common in containers)
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown(self::$logFile, 'xc_vm');
            @chgrp(self::$logFile, 'xc_vm');
            @chmod(self::$logFile, 0664);
        }

        // In development mode, display a readable message on screen
        if (self::$development) {
            self::output($data);
        }
    }

    /* ================= TRACE ================= */

    /**
     * Builds a stack trace for an exception chain (including previous exceptions).
     *
     * @param Throwable $e The exception
     *
     * @return string Formatted trace string
     */
    private static function buildExceptionTrace(Throwable $e): string {
        $out = [];

        do {
            // Main exception info
            $out[] = sprintf(
                "%s: %s in %s:%d",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            // Call stack for the current exception
            foreach ($e->getTrace() as $i => $t) {
                $out[] = sprintf(
                    "#%d %s(%s): %s()",
                    $i,
                    $t['file'] ?? '[internal]',
                    $t['line'] ?? '?',
                    $t['function']
                );
            }

            $e = $e->getPrevious();
            if ($e) {
                $out[] = "---- CAUSED BY ----";
            }
        } while ($e);

        return implode("\n", $out);
    }

    /**
     * Builds a stack trace from the array returned by debug_backtrace().
     *
     * @param array $trace Backtrace array
     *
     * @return string Formatted trace string
     */
    private static function buildTrace(array $trace): string {
        $out = [];

        foreach ($trace as $i => $t) {
            $out[] = sprintf(
                "#%d %s(%s): %s()",
                $i,
                $t['file'] ?? '[internal]',
                $t['line'] ?? '?',
                $t['function']
            );
        }

        return implode("\n", $out);
    }

    /* ================= HELPERS ================= */

    /**
     * Maps a PHP error constant to a human-readable string.
     *
     * @param int $errno Error level constant
     *
     * @return string Error type (ERROR, WARNING, NOTICE, INFO)
     */
    private static function mapErrorLevel(int $errno): string {
        return match ($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR => 'ERROR',
            E_WARNING, E_USER_WARNING             => 'WARNING',
            E_NOTICE, E_USER_NOTICE               => 'NOTICE',
            default                               => 'INFO',
        };
    }

    /**
     * Outputs error/exception information to the screen in a readable format.
     * Format depends on the environment (CLI or Web).
     *
     * @param array $d Event data (from the log method)
     */
    private static function output(array $d): void {
        if (php_sapi_name() === 'cli') {
            // Color scheme for terminal
            $color = match ($d['type']) {
                'FATAL', 'ERROR' => "\033[41m\033[97m", // red background, white text
                'WARNING'       => "\033[43m\033[30m", // yellow background, black text
                'NOTICE'        => "\033[44m\033[97m", // blue background, white text
                default         => "\033[45m\033[97m", // magenta background, white text
            };

            echo "\n{$color} {$d['type']} \033[0m ";
            echo date('Y-m-d H:i:s', $d['time']) . "\n";
            echo "{$d['log_message']}\n";
            echo "{$d['file']}:{$d['line']}\n";

            if (!empty($d['log_extra'])) {
                echo str_repeat('-', 60) . "\n";
                echo $d['log_extra'] . "\n";
            }
        } else {
            // Web environment output
            echo "<div style='
                border:2px solid #f00;
                background:#fff0f0;
                padding:10px;
                margin:10px;
                font-family:monospace;
                color:#000;
            '>";
            echo "<b>{$d['type']}</b> ";
            echo "<small>" . date('Y-m-d H:i:s', $d['time']) . "</small><br>";
            echo htmlspecialchars($d['log_message'], ENT_SUBSTITUTE) . "<br>";
            echo "<small>{$d['file']}:{$d['line']}</small>";

            if (!empty($d['log_extra'])) {
                echo "<pre style='margin-top:10px;background:#fff;padding:10px;border:1px solid #ccc;'>";
                echo htmlspecialchars($d['log_extra'], ENT_SUBSTITUTE);
                echo "</pre>";
            }
            echo "</div>";
        }
    }
}
