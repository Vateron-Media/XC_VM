<?php

namespace XcVm\Core\Events\Stream;

/**
 * Fired after a stream category has been deleted.
 *
 * Lets modules (e.g. watch, whose folders name a category) drop the deleted
 * category from their own data without core category code referencing
 * module-owned tables.
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class CategoryDeletedEvent {
	/**
	 * @param int $categoryId Id of the category that was deleted.
	 */
	public function __construct(
		public readonly int $categoryId,
	) {
	}
}
