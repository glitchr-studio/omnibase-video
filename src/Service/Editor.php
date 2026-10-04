<?php

namespace Base\Video\Service;

use Base\Entity\Thread\Tag;
use Base\Video\Entity\Source;
use Base\Video\Entity\Video;
use Base\Video\Model\LinkDetails;
use Base\Video\Model\VideoDetails;
use Base\Entity\User;
use Base\Service\Embeds;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * What the studio writes: a film's details applied (the keywords made
 * omnibase tags, the poster a still or a picture sent), a film of another
 * platform added by its address.
 */
class Editor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Pictures $pictures,
        private readonly Channels $channels,
        private readonly Embeds $embeds,
        private readonly Storage $storage,
    ) {
    }

    public function apply(VideoDetails $details, Video $video): void
    {
        $video->setTitle(trim((string) $details->title));
        $video->setContent($details->description ? trim($details->description) : null);
        $video->setCategory($details->category);
        $video->setLanguage($details->language);
        $video->setLicense($details->license);
        $video->setCommentsEnabled($details->commentsEnabled);
        if (!$video->isTakenDown()) {
            $video->setVisibility($details->visibility, $details->publishAt);
        }

        foreach ($video->getTags()->toArray() as $tag) {
            $video->removeTag($tag);
        }
        foreach ($this->tags($details->keywords()) as $tag) {
            $video->addTag($tag);
        }

        if ($details->posterFile) {
            $video->setPoster($this->pictures->poster($video, $details->posterFile));
        } elseif ($details->poster && $details->poster !== $video->getPoster() && $this->isOwnStill($video, $details->poster)) {
            $video->setPoster($details->poster);
        }
        $video->poke();
        $this->entityManager->flush();
    }

    /** A film of another platform: its page, framed through embed_url(); null when nothing can frame it. */
    public function link(LinkDetails $details, User $user): ?Video
    {
        $frame = $this->embeds->resolve((string) $details->url);
        if (!$frame || !$frame['src']) {
            return null;
        }
        $title = trim((string) $details->title) ?: ($frame['title'] ?? null) ?: (string) $details->url;
        $video = new Video($user, null, mb_substr($title, 0, 150));
        $video->setSlug(Uploads::slug());
        $video->setChannel($this->channels->forMember($user));
        $video->setExternalUrl((string) $details->url);
        $video->addSource((new Source(Source::EMBED, (string) $details->url))->setReference($frame['provider'], $frame['src']));
        $video->setProcessing(Video::PROCESSING_NONE);
        $video->setVisibility(Video::VISIBILITY_PRIVATE);
        $this->entityManager->persist($video);
        $this->entityManager->flush();

        return $video;
    }

    /**
     * The tags for these keywords, found by their slug or made.
     *
     * @param list<string> $keywords
     *
     * @return list<Tag>
     */
    public function tags(array $keywords): array
    {
        $tags = [];
        $repository = $this->entityManager->getRepository(Tag::class);
        $slugger = new AsciiSlugger();
        foreach ($keywords as $keyword) {
            $slug = strtolower((string) $slugger->slug($keyword));
            if ('' === $slug) {
                continue;
            }
            $tag = $repository->findOneBy(['slug' => $slug]);
            if (!$tag) {
                $tag = new Tag($keyword, $slug);
                $this->entityManager->persist($tag);
            }
            $tags[$slug] = $tag;
        }

        return array_values($tags);
    }

    private function isOwnStill(Video $video, string $url): bool
    {
        $path = $this->storage->pathOf($url);

        return null !== $path && null !== $video->getStorageKey() && str_contains($path, '/'.$video->getStorageKey().'/') && is_file($path);
    }
}
