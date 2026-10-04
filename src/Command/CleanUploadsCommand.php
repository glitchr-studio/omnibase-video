<?php

namespace Base\Video\Command;

use Base\Video\Repository\VideoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * tusd's leftovers: uploads started and never finished, and finished ones
 * no video claims, older than --days. The ones still waiting for their
 * transcoding are kept.
 */
#[AsCommand(name: 'video:uploads:clean', description: 'Removes the abandoned uploads (unfinished, or claimed by no video) older than --days.')]
final class CleanUploadsCommand
{
    public function __construct(
        private readonly VideoRepository $videos,
        #[Autowire('%video.upload.directory%')] private readonly string $directory,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Age in days.')] int $days = 3, #[Option(description: 'Only say what would go.')] bool $dryRun = false): int
    {
        if (!is_dir($this->directory)) {
            return Command::SUCCESS;
        }
        $limit = time() - $days * 86400;
        $removed = 0;
        foreach (glob(rtrim($this->directory, '/').'/*.info') ?: [] as $info) {
            $id = basename($info, '.info');
            if (filemtime($info) > $limit) {
                continue;
            }
            $video = $this->videos->findOneByUploadId($id);
            if ($video && $video->isProcessing()) {
                continue;
            }
            $io->writeln(($dryRun ? 'would remove ' : 'removing ').$id);
            if (!$dryRun) {
                (new Filesystem())->remove([$info, substr($info, 0, -5)]);
            }
            ++$removed;
        }
        $io->writeln(sprintf('%d uploads %s.', $removed, $dryRun ? 'to remove' : 'removed'));

        return Command::SUCCESS;
    }
}
