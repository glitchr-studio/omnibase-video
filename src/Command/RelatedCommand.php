<?php

namespace Base\Video\Command;

use Base\Video\Message\ComputeRelated;
use Base\Video\Suggest\RelatedCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/** The suggestions' Related table computed again - from cron, at night. */
#[AsCommand(name: 'video:related', description: 'Computes the videos\' neighbours ("à suivre") again (--async: through Messenger).')]
final class RelatedCommand
{
    public function __construct(private readonly RelatedCalculator $calculator, private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Dispatch it to the worker instead of running it here.')] bool $async = false): int
    {
        if ($async) {
            $this->bus->dispatch(new ComputeRelated());
            $io->writeln('Dispatched.');

            return Command::SUCCESS;
        }
        $started = microtime(true);
        $rows = $this->calculator->compute();
        $io->writeln(sprintf('%d neighbours written in %.1f s.', $rows, microtime(true) - $started));

        return Command::SUCCESS;
    }
}
