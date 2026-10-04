<?php

namespace Base\Video\MessageHandler;

use Base\Video\Message\FlushViews;
use Base\Video\Service\Views;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FlushViewsHandler
{
    public function __construct(private readonly Views $views)
    {
    }

    public function __invoke(FlushViews $message): void
    {
        $this->views->flush();
    }
}
