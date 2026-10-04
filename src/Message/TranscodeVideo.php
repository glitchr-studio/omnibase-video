<?php

namespace Base\Video\Message;

/**
 * A film to transcode: sent by the upload's post-finish hook (or the
 * replacement of a file), handled by the worker. Route it to an async
 * transport (framework.messenger.routing).
 */
final class TranscodeVideo
{
    public function __construct(
        public readonly int $videoId,
        public readonly string $source,
    ) {
    }
}
