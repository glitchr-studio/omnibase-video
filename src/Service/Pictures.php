<?php

namespace Base\Video\Service;

use Base\Video\Entity\Channel;
use Base\Video\Entity\Video;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The pictures a member sends in the studio - a poster of their own, a
 * channel's picture and banner - kept with the films (served by the same
 * address), under random names.
 */
class Pictures
{
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly Storage $storage,
        #[Autowire('%video.public_path%')] private readonly string $publicPath = '/media/videos',
    ) {
    }

    public function poster(Video $video, UploadedFile $file): string
    {
        $name = 'custom-'.bin2hex(random_bytes(4)).'.'.$this->extension($file);
        $file->move($this->storage->directory($video), $name);

        return $this->storage->url($video, $name);
    }

    /** @param 'avatar'|'banner' $kind */
    public function channel(Channel $channel, UploadedFile $file, string $kind): string
    {
        $directory = $this->storage->getRoot().'/channels';
        (new Filesystem())->mkdir($directory, 0775);
        $name = sprintf('%s-%s-%s.%s', $channel->getId() ?? 'new', $kind, bin2hex(random_bytes(6)), $this->extension($file));
        $file->move($directory, $name);

        return rtrim($this->publicPath, '/').'/channels/'.$name;
    }

    private function extension(UploadedFile $file): string
    {
        $type = (string) $file->getMimeType();
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException(sprintf('A picture is a JPEG, a PNG or a WebP, not "%s".', $type));
        }

        return self::TYPES[$type];
    }
}
