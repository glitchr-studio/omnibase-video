<?php

namespace Base\Video\Model;

use Base\Video\Entity\Playlist;
use Symfony\Component\Validator\Constraints as Assert;

class PlaylistDetails
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public ?string $title = null;

    #[Assert\Length(max: 2000)]
    public ?string $description = null;

    #[Assert\Choice(choices: Playlist::VISIBILITIES)]
    public string $visibility = Playlist::PRIVATE;

    public static function of(Playlist $playlist): self
    {
        $details = new self();
        $details->title = $playlist->getTitle();
        $details->description = $playlist->getDescription();
        $details->visibility = $playlist->getVisibility();

        return $details;
    }
}
