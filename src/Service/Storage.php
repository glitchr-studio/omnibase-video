<?php

namespace Base\Video\Service;

use Base\Video\Entity\Video;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Where a video's files live: one directory a video under video.storage,
 * named by a random key (a private film's files cannot be guessed), served
 * by the web server at video.public_path. What the entities keep are the
 * public addresses (/media/videos/<key>/master.m3u8), so the pages need no
 * service to show a poster.
 */
class Storage
{
    private readonly Filesystem $filesystem;

    public function __construct(
        #[Autowire('%video.storage%')] private readonly string $root,
        #[Autowire('%video.public_path%')] private readonly string $publicPath = '/media/videos',
    ) {
        $this->filesystem = new Filesystem();
    }

    public function getRoot(): string
    {
        return rtrim($this->root, '/');
    }

    /** The video's directory, its key made the first time. */
    public function directory(Video $video, bool $create = true): string
    {
        if (null === $video->getStorageKey()) {
            $video->setStorageKey(bin2hex(random_bytes(16)));
        }
        $directory = $this->getRoot().'/'.$video->getStorageKey();
        if ($create) {
            $this->filesystem->mkdir($directory, 0775);
        }

        return $directory;
    }

    /** A fresh directory beside it, to build a new ladder before it replaces the old one. */
    public function workspace(Video $video): string
    {
        $directory = $this->directory($video).'.build-'.bin2hex(random_bytes(4));
        $this->filesystem->mkdir($directory, 0775);

        return $directory;
    }

    /** The workspace becomes the video's directory; the previous files go. */
    public function promote(Video $video, string $workspace): void
    {
        $directory = $this->directory($video, false);
        $old = $directory.'.old-'.bin2hex(random_bytes(4));
        if (is_dir($directory)) {
            $this->filesystem->rename($directory, $old);
        }
        $this->filesystem->rename($workspace, $directory);
        $this->filesystem->remove($old);
    }

    public function discard(string $workspace): void
    {
        if (str_starts_with($workspace, $this->getRoot().'/')) {
            $this->filesystem->remove($workspace);
        }
    }

    /** The address a file of the video's directory is served at. */
    public function url(Video $video, string $file): string
    {
        return rtrim($this->publicPath, '/').'/'.$this->directoryName($video).'/'.ltrim($file, '/');
    }

    /** The file behind one of our addresses, or null for anything else. */
    public function pathOf(string $url): ?string
    {
        $prefix = rtrim($this->publicPath, '/').'/';
        if (!str_starts_with($url, $prefix)) {
            return null;
        }
        $relative = substr(strtok($url, '?#') ?: $url, \strlen($prefix));
        if (str_contains($relative, '..')) {
            return null;
        }

        return $this->getRoot().'/'.$relative;
    }

    public function remove(Video $video): void
    {
        if (null !== $video->getStorageKey()) {
            $this->filesystem->remove($this->getRoot().'/'.$video->getStorageKey());
        }
    }

    /** Bytes a video's files take. */
    public function size(Video $video): int
    {
        if (null === $video->getStorageKey() || !is_dir($directory = $this->getRoot().'/'.$video->getStorageKey())) {
            return 0;
        }
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function directoryName(Video $video): string
    {
        if (null === $video->getStorageKey()) {
            $video->setStorageKey(bin2hex(random_bytes(16)));
        }

        return $video->getStorageKey();
    }
}
