<?php

namespace XcVm\Core\Events\Vod;

use XcVm\Core\Events\AbstractEvent;

/**
 * MediaAnalyzedEvent
 *
 * Dispatched by VodCronJob when a media stream (movie or episode) finishes analysis.
 * Notification modules subscribe to this event to trigger automated notifications.
 */
class MediaAnalyzedEvent extends AbstractEvent {
	public function __construct(
		public readonly int $streamId,
		public readonly int $type
	) {
	}
}
