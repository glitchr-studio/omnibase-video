<?php

namespace Base\Video\Message;

/**
 * What a viewer did, for Gorse (video.suggest.provider: gorse): read (a
 * playback started), watch (a view counted), like, or a negative "not
 * interested" - sent through Messenger so a slow Gorse never slows a page.
 */
final class SuggestFeedback
{
    public function __construct(
        public readonly string $type,
        public readonly string $userId,
        public readonly int $videoId,
        public readonly ?\DateTimeImmutable $at = null,
    ) {
    }
}
