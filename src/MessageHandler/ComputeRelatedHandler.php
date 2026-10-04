<?php

namespace Base\Video\MessageHandler;

use Base\Video\Message\ComputeRelated;
use Base\Video\Suggest\RelatedCalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ComputeRelatedHandler
{
    public function __construct(private readonly RelatedCalculator $calculator)
    {
    }

    public function __invoke(ComputeRelated $message): void
    {
        $this->calculator->compute();
    }
}
