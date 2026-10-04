<?php

namespace Base\Video\Model;

use Base\Video\Entity\Video;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the studio's form edits of a film (omnibase's FormFactory takes no
 * entity as form data): its words, its filing, who sees it and when, its
 * comments, its poster.
 */
class VideoDetails
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public ?string $title = null;

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    public ?string $category = null;

    /** Keywords, separated by commas. */
    #[Assert\Length(max: 500)]
    public ?string $tags = null;

    #[Assert\Language]
    public ?string $language = null;

    public string $license = 'standard';

    #[Assert\Choice(choices: Video::VISIBILITIES)]
    public string $visibility = Video::VISIBILITY_PRIVATE;

    public ?\DateTimeInterface $publishAt = null;

    public bool $commentsEnabled = true;

    /** One of the stills, chosen as the poster (its address). */
    public ?string $poster = null;

    #[Assert\Image(maxSize: '8M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'])]
    public ?UploadedFile $posterFile = null;

    public static function of(Video $video): self
    {
        $details = new self();
        $details->title = $video->getTitle();
        $details->description = $video->getContent();
        $details->category = $video->getCategory();
        $details->tags = implode(', ', $video->getSearchTags());
        $details->language = $video->getLanguage();
        $details->license = $video->getLicense();
        $details->visibility = $video->getVisibility();
        $details->publishAt = Video::VISIBILITY_SCHEDULED === $details->visibility ? $video->getPublishedAt() : null;
        $details->commentsEnabled = $video->areCommentsEnabled();
        $details->poster = $video->getPoster();

        return $details;
    }

    /** @return list<string> the keywords, trimmed, once each, ten at most */
    public function keywords(): array
    {
        $words = array_map(fn ($w) => trim(mb_substr($w, 0, 40)), explode(',', (string) $this->tags));

        return \array_slice(array_values(array_unique(array_filter($words))), 0, 10);
    }
}
