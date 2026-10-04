<?php

namespace Base\Video\MessageHandler;

use Base\Video\Message\SuggestFeedback;
use Base\Video\Suggest\GorseSuggest;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Hands a viewer's gesture to Gorse; a Gorse that is down loses it, nothing else does. */
#[AsMessageHandler]
final class SuggestFeedbackHandler
{
    public function __construct(private readonly GorseSuggest $gorse)
    {
    }

    public function __invoke(SuggestFeedback $message): void
    {
        $this->gorse->send($message->type, $message->userId, $message->videoId, $message->at);
    }
}
