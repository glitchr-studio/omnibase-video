<?php

namespace Base\Video\Model;

use Base\Video\Entity\Channel;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

class ChannelDetails
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9][a-z0-9-]{1,78}$/', message: 'video.channel.slug_invalid')]
    public ?string $slug = null;

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    /** @var list<string> */
    public array $shelves = Channel::SHELVES;

    #[Assert\Image(maxSize: '4M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'])]
    public ?UploadedFile $avatarFile = null;

    #[Assert\Image(maxSize: '8M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'])]
    public ?UploadedFile $bannerFile = null;

    public static function of(Channel $channel): self
    {
        $details = new self();
        $details->name = $channel->getName();
        $details->slug = $channel->getSlug();
        $details->description = $channel->getDescription();
        $details->shelves = $channel->getShelves();

        return $details;
    }
}
