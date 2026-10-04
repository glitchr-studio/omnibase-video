<?php

namespace Base\Video\Command;

use Base\Video\Message\FlushViews;
use Base\Video\Service\Views;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/** The views counted since the last run, added to Video::$views - from cron, every few minutes. */
#[AsCommand(name: 'video:views:flush', description: 'Adds the views counted since the last flush to the videos (--async: through Messenger).')]
final class FlushViewsCommand
{
    public function __construct(private readonly Views $views, private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Dispatch it to the worker instead of running it here.')] bool $async = false): int
    {
        if ($async) {
            $this->bus->dispatch(new FlushViews());
            $io->writeln('Dispatched.');

            return Command::SUCCESS;
        }
        $io->writeln(sprintf('%d views added.', $this->views->flush()));

        return Command::SUCCESS;
    }
}
