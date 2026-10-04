<?php

namespace Base\Video\Service;

use Base\Video\Entity\Source;
use Base\Video\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A film's way from the uploaded file to what plays: running, the ladder
 * built beside the old one (a replacement keeps playing until the new one
 * is whole), the sources, the poster unless its author chose one, the
 * storyboard, ready - or failed, with why. The original is removed once the
 * ladder exists (video.upload.keep_originals: 0).
 */
class Processing
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Storage $storage,
        private readonly Transcoder $transcoder,
        private readonly Notifier $notifier,
        #[Autowire('%video.upload.directory%')] private readonly string $uploads,
        #[Autowire('%video.upload.auto_publish%')] private readonly bool $autoPublish = false,
        #[Autowire('%video.upload.keep_originals%')] private readonly int $keepOriginals = 0,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function transcode(Video $video, string $source): bool
    {
        if (!is_file($source)) {
            return $this->fail($video, sprintf('The uploaded file is not there any more (%s).', basename($source)));
        }

        $video->setProcessing(Video::PROCESSING_RUNNING)->setFailure(null);
        $this->storage->directory($video);
        $this->entityManager->flush();

        $workspace = $this->storage->workspace($video);
        try {
            $result = $this->transcoder->transcode($source, $workspace);
        } catch (\Throwable $e) {
            $this->storage->discard($workspace);
            $this->logger?->error('Transcoding video {id} failed: {message}', ['id' => $video->getId(), 'message' => $e->getMessage()]);

            return $this->fail($video, $e->getMessage());
        }

        // A poster its author chose (a picture of theirs, or another still) stays; ours are replaced.
        $customPoster = $video->getPoster() && !str_contains((string) $video->getPoster(), '/frame-') && !str_ends_with((string) $video->getPoster(), '/poster.jpg');
        $this->storage->promote($video, $workspace);

        $video->clearSources();
        $master = (new Source(Source::HLS, $this->storage->url($video, 'master.m3u8')))->setSize($result['width'], $result['height']);
        $video->addSource($master);
        foreach ($result['rungs'] as $rung) {
            $video->addSource((new Source(Source::HLS, $this->storage->url($video, $rung['path']), Source::RENDITION))
                ->setSize($rung['width'], $rung['height'])->setBitrate($rung['bitrate'] * 1000));
        }
        $video->setDuration((int) round($result['duration']))->setSize($result['width'], $result['height']);
        if (!$customPoster) {
            $video->setPoster($result['poster'] ? $this->storage->url($video, $result['poster']) : null);
        }
        $video->setStoryboard($result['storyboard'] ? $this->storage->url($video, $result['storyboard']) : null);
        $video->setProcessing(Video::PROCESSING_READY)->setFailure(null);
        if ($this->autoPublish && Video::VISIBILITY_PRIVATE === $video->getVisibility() && !$video->isTakenDown()) {
            $video->setVisibility(Video::VISIBILITY_PUBLIC);
        }
        $video->poke();
        $this->entityManager->flush();

        if ($this->keepOriginals <= 0) {
            $this->removeUpload($source);
        }
        $this->notifier->transcoded($video);

        return true;
    }

    /** @return list<string> the stills a poster can be chosen from */
    public function frames(Video $video): array
    {
        if (null === $video->getStorageKey()) {
            return [];
        }
        $frames = [];
        foreach (glob($this->storage->directory($video, false).'/frame-*.jpg') ?: [] as $file) {
            $frames[] = $this->storage->url($video, basename($file));
        }
        sort($frames, \SORT_NATURAL);

        return $frames;
    }

    /** tusd's file and its .info, if they are where uploads are kept. */
    public function removeUpload(string $source): void
    {
        $root = realpath($this->uploads);
        $path = realpath($source);
        if (false === $root || false === $path || !str_starts_with($path, $root.'/')) {
            return;
        }
        (new Filesystem())->remove([$path, $path.'.info']);
    }

    private function fail(Video $video, string $why): bool
    {
        $video->setProcessing(Video::PROCESSING_FAILED)->setFailure($why);
        $this->entityManager->flush();
        $this->notifier->failed($video);

        return false;
    }
}
