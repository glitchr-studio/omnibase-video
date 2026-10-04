<?php

namespace Base\Video\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** A film of another platform, added by its address (framed through embed_url()). */
class LinkDetails
{
    #[Assert\NotBlank]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    public ?string $url = null;

    #[Assert\Length(max: 150)]
    public ?string $title = null;
}
