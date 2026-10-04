<?php

namespace Base\Video\MessageHandler;

use Base\Video\Message\TranscodeVideo;
use Base\Video\Repository\VideoRepository;
use Base\Video\Service\Processing;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Transcodes an uploaded film (Service\Processing::transcode()). */
#[AsMessageHandler]
final class TranscodeVideoHandler
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly Processing $processing,
    ) {
    }

    public function __invoke(TranscodeVideo $message): void
    {
        // Deleted since: nothing to do.
        if ($video = $this->videos->find($message->videoId)) {
            $this->processing->transcode($video, $message->source);
        }
    }
}
