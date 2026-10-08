<?php

namespace XcVm\Core\Events\Stream;

/**
 * Fired after a transcoding profile (`profiles`) has been deleted.
 *
 * Lets modules (e.g. watch, whose folders name a profile) stop pointing at
 * it without core code referencing module-owned tables.
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class TranscodeProfileDeletedEvent {
	/**
	 * @param int $profileId Id of the profile that was deleted.
	 */
	public function __construct(
		public readonly int $profileId,
	) {
	}
}
