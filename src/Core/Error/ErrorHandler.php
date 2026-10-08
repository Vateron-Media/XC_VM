<?php

/**
 * Error-page functions — backward-compatibility shim.
 *
 * The rendering and branching logic now lives in ErrorResponder; these global
 * functions stay so the ~139 legacy generateError()/generate404() call sites
 * keep working unchanged and keep terminating the request in production. The
 * side-effecting emit() (echo / http_response_code / exit) is centralized in
 * ErrorResponder. This file is loaded globally via composer autoload.files; the
 * function_exists() guards keep it safe against any stray double-load.
 *
 * @package XC_VM_Core_Error
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

use XcVm\Core\Error\ErrorResponder;
use XcVm\Core\Logging\RefusalLog;

if (!function_exists('generateError')) {
	/**
	 * Render an error page (styled in debug mode, a bare 404/status otherwise).
	 *
	 * @param string   $rError Error code (key from ErrorResponder::codes()).
	 * @param bool     $rKill  Terminate after output (default: true).
	 * @param int|null $rCode  HTTP status code (null = 404).
	 */
	function generateError(string $rError, bool $rKill = true, ?int $rCode = null): void {
		global $rSettings;

		// A stream id that does not exist, or credentials nothing matches: into the client logs.
		RefusalLog::record($rError);
		ErrorResponder::emit(ErrorResponder::respondError(
			$rError,
			is_array($rSettings ?? null) ? $rSettings : [],
			$rKill,
			$rCode
		));
	}
}

if (!function_exists('generate404')) {
	/**
	 * Render the standard "404 Not Found" page (mimics nginx).
	 *
	 * @param bool $rKill Terminate after output (default: true).
	 */
	function generate404(bool $rKill = true): void {
		ErrorResponder::emit(ErrorResponder::respond404($rKill));
	}
}
