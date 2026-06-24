<?php

namespace XcVm\Core\Exception;

/**
 * Base exception for the XC_VM framework.
 *
 * All framework-generated exceptions extend this class so callers can
 * catch XC_VM errors independently of generic PHP exceptions:
 *
 *   try { ... } catch (XcVmException $e) { ... }
 *
 * Still extends \RuntimeException so existing catch(\RuntimeException) blocks
 * remain unaffected — no breaking change.
 *
 * @package XC_VM_Core_Exception
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class XcVmException extends \RuntimeException {}
