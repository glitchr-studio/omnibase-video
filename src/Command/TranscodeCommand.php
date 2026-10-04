<?php

namespace Base\Video\Command;

use Base\Video\Repository\VideoRepository;
use Base\Video\Service\Processing;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** One film transcoded here and now, from a file: a back-office repair, an import. */
#[AsCommand(name: 'video:transcode', description: 'Transcodes a file for a video, here and now (the video keeps playing its old ladder until the new one is whole).')]
final class TranscodeCommand
{
    public function __construct(private readonly VideoRepository $videos, private readonly Processing $processing)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The video\'s id or slug.')] string $video, #[Argument('The film file.')] string $file): int
    {
        $found = ctype_digit($video) ? $this->videos->find((int) $video) : $this->videos->findOneBy(['slug' => $video]);
        if (!$found) {
            $io->error('No such video.');

            return Command::FAILURE;
        }
        $started = microtime(true);
        if (!$this->processing->transcode($found, $file)) {
            $io->error((string) $found->getFailure());

            return Command::FAILURE;
        }
        $io->success(sprintf('%s: %d rungs, %s, in %.1f s.', $found->getSlug(), \count($found->getRenditions()), $found->getDurationText(), microtime(true) - $started));

        return Command::SUCCESS;
    }
}
