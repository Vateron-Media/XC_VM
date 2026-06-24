<?php

/**
 * Thrown when ModuleLoader detects a circular dependency between modules.
 *
 * Example: module A depends on B, B depends on A.
 *
 * @package XC_VM_Core_Exception_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleCycleException extends ModuleException {}
