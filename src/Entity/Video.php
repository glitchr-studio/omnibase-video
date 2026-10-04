<?php

namespace Base\Video\Entity;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread;
use Base\Entity\User;
use Base\Enum\ThreadState;
use Base\Service\Model\LinkableInterface;
use Base\Video\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Typesense\Bundle\TypesenseInterface;
use Typesense\Bundle\TypesenseTrait;

/**
 * A film. An omnibase thread - its title, its description (the content),
 * its slug, its owners, its tags (the keywords), its likes, its states and
 * its trash - and what a film adds: the channel it is on, where it plays
 * from (its Sources: our own HLS, a remote HLS read without a copy, or a
 * platform's player), how long it is, its poster and storyboard, where its
 * processing stands, how many times it was seen.
 *
 * The visibility is the thread's state: public = PUBLISH, unlisted =
 * SECRET (reachable by its address only), private = DRAFT, scheduled =
 * FUTURE with publishedAt (omnibase's thread:publishable puts it online).
 *
 * Strings rather than PHP enums for the columns: omnibase's Thread reads
 * its properties through __get, and the uploader rehydrates raw values.
 */
#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'video')]
#[ORM\Index(columns: ['processing'], name: 'video_processing_idx')]
#[ORM\Index(columns: ['views'], name: 'video_views_idx')]
#[DiscriminatorEntry(value: 'video')]
class Video extends Thread implements LinkableInterface, TypesenseInterface
{
    use TypesenseTrait;

    public const PROCESSING_NONE = 'none';         // nothing to do: a remote or embedded film
    public const PROCESSING_UPLOADED = 'uploaded'; // the file is in, the transcoding is queued
    public const PROCESSING_RUNNING = 'running';
    public const PROCESSING_READY = 'ready';
    public const PROCESSING_FAILED = 'failed';

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_UNLISTED = 'unlisted';
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_SCHEDULED = 'scheduled';
    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_UNLISTED, self::VISIBILITY_PRIVATE, self::VISIBILITY_SCHEDULED];

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-film'];
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        return $this->getRouter()->generate('video_watch', array_merge($routeParameters, ['slug' => $this->getSlug()]), $referenceType);
    }

    public function __typesense(): ?string
    {
        return $this->getTitle();
    }

    #[ORM\ManyToOne(targetEntity: Channel::class, inversedBy: 'videos')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Channel $channel = null;

    /** @var Collection<int, Source> */
    #[ORM\OneToMany(targetEntity: Source::class, mappedBy: 'video', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['height' => 'DESC', 'id' => 'ASC'])]
    protected Collection $sources;

    #[ORM\Column(length: 16, options: ['default' => self::PROCESSING_NONE])]
    protected string $processing = self::PROCESSING_NONE;

    /** Why the last transcoding failed, for its author and the back office. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $failure = null;

    /** Seconds. */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $duration = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $width = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $height = null;

    /** The still shown before it plays: a path under the storage's public address, or an absolute URL. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $poster = null;

    /** The WebVTT of the time bar's previews (a sprite and its #xywh boxes). */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $storyboard = null;

    /** The video's own directory under the storage: random, so a private film's files cannot be guessed. */
    #[ORM\Column(length: 40, nullable: true, unique: true)]
    protected ?string $storageKey = null;

    /** tusd's id of the upload it came from (a replacement keeps the first one's video). */
    #[ORM\Column(length: 128, nullable: true)]
    protected ?string $uploadId = null;

    /** The original's weight, in bytes: what the member's quota counts. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    protected ?string $uploadSize = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $originalName = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    protected int $views = 0;

    /** The views it had where it was imported from (shown apart, never mixed with ours). */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $legacyViews = null;

    #[ORM\Column(length: 32, nullable: true)]
    protected ?string $category = null;

    /** ISO 639-1. */
    #[ORM\Column(length: 8, nullable: true)]
    protected ?string $language = null;

    #[ORM\Column(length: 16, options: ['default' => 'standard'])]
    protected string $license = 'standard';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    protected bool $commentsEnabled = true;

    /** When a film plays from another platform: its page there (framed through embed_url()). */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $externalUrl = null;

    /** Taken down by the moderators: why (the author is told), and when. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $takedownReason = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $takenDownAt = null;

    public function __construct(?User $owner = null, ?Thread $parent = null, ?string $title = null, ?string $slug = null)
    {
        parent::__construct($owner, $parent, $title, $slug);
        $this->sources = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) ($this->getTitle() ?? $this->getSlug() ?? '');
    }

    public function getChannel(): ?Channel { return $this->channel; }
    public function setChannel(?Channel $channel): self { $this->channel = $channel; return $this; }

    /** @return Collection<int, Source> */
    public function getSources(): Collection { return $this->sources; }

    public function addSource(Source $source): self
    {
        if (!$this->sources->contains($source)) {
            $this->sources->add($source);
            $source->setVideo($this);
        }

        return $this;
    }

    public function removeSource(Source $source): self
    {
        $this->sources->removeElement($source);

        return $this;
    }

    public function clearSources(): self
    {
        foreach ($this->sources->toArray() as $source) {
            $this->sources->removeElement($source);
        }

        return $this;
    }

    /** The source the player starts from: the HLS master (ours or remote), else a progressive file, else an embed. */
    public function getPlayableSource(): ?Source
    {
        foreach ([Source::HLS, Source::REMOTE_HLS, Source::MP4, Source::EMBED] as $kind) {
            foreach ($this->sources as $source) {
                if ($kind === $source->getKind() && Source::RENDITION !== $source->getRole()) {
                    return $source;
                }
            }
        }

        return null;
    }

    /** @return list<Source> the renditions of the ladder, the tallest first */
    public function getRenditions(): array
    {
        return array_values($this->sources->filter(fn (Source $source) => Source::RENDITION === $source->getRole())->toArray());
    }

    public function isEmbedded(): bool
    {
        return Source::EMBED === $this->getPlayableSource()?->getKind();
    }

    public function isRemote(): bool
    {
        return Source::REMOTE_HLS === $this->getPlayableSource()?->getKind();
    }

    public function isPlayable(): bool
    {
        if (null === $source = $this->getPlayableSource()) {
            return false;
        }

        return \in_array($source->getKind(), [Source::REMOTE_HLS, Source::EMBED], true) || self::PROCESSING_READY === $this->processing;
    }

    public function getProcessing(): string { return $this->processing; }
    public function setProcessing(string $processing): self { $this->processing = $processing; return $this; }
    public function isProcessing(): bool { return \in_array($this->processing, [self::PROCESSING_UPLOADED, self::PROCESSING_RUNNING], true); }
    public function hasFailed(): bool { return self::PROCESSING_FAILED === $this->processing; }

    public function getFailure(): ?string { return $this->failure; }
    public function setFailure(?string $failure): self { $this->failure = null !== $failure ? mb_substr($failure, 0, 4000) : null; return $this; }

    public function getDuration(): ?int { return $this->duration; }
    public function setDuration(?int $duration): self { $this->duration = null !== $duration && $duration > 0 ? $duration : null; return $this; }

    /** 1:02:03, 4:05, 0:07. */
    public function getDurationText(): string
    {
        return self::formatTime($this->duration);
    }

    public static function formatTime(?int $seconds): string
    {
        if (null === $seconds || $seconds < 0) {
            return '';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function setSize(?int $width, ?int $height): self { $this->width = $width ?: null; $this->height = $height ?: null; return $this; }

    /** "16 / 9", for the player's frame before anything loads. */
    public function getRatio(): string
    {
        return $this->width && $this->height ? $this->width.' / '.$this->height : '16 / 9';
    }

    public function getPoster(): ?string { return $this->poster; }
    public function setPoster(?string $poster): self { $this->poster = $poster ?: null; return $this; }

    public function getStoryboard(): ?string { return $this->storyboard; }
    public function setStoryboard(?string $storyboard): self { $this->storyboard = $storyboard ?: null; return $this; }

    public function getStorageKey(): ?string { return $this->storageKey; }
    public function setStorageKey(?string $storageKey): self { $this->storageKey = $storageKey; return $this; }

    public function getUploadId(): ?string { return $this->uploadId; }
    public function setUploadId(?string $uploadId): self { $this->uploadId = $uploadId; return $this; }

    public function getUploadSize(): int { return (int) $this->uploadSize; }
    public function setUploadSize(?int $uploadSize): self { $this->uploadSize = null !== $uploadSize ? (string) $uploadSize : null; return $this; }

    public function getOriginalName(): ?string { return $this->originalName; }
    public function setOriginalName(?string $originalName): self { $this->originalName = null !== $originalName ? mb_substr($originalName, 0, 255) : null; return $this; }

    public function getViews(): int { return $this->views; }
    public function setViews(int $views): self { $this->views = max(0, $views); return $this; }

    public function getLegacyViews(): ?int { return $this->legacyViews; }
    public function setLegacyViews(?int $legacyViews): self { $this->legacyViews = $legacyViews; return $this; }

    /** Ours and the imported ones: what a card shows. */
    public function getTotalViews(): int { return $this->views + (int) $this->legacyViews; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): self { $this->category = $category ?: null; return $this; }

    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $language): self { $this->language = $language ?: null; return $this; }

    public function getLicense(): string { return $this->license; }
    public function setLicense(?string $license): self { $this->license = $license ?: 'standard'; return $this; }

    public function areCommentsEnabled(): bool { return $this->commentsEnabled; }
    public function isCommentsEnabled(): bool { return $this->commentsEnabled; }
    public function setCommentsEnabled(bool $commentsEnabled): self { $this->commentsEnabled = $commentsEnabled; return $this; }

    public function getExternalUrl(): ?string { return $this->externalUrl; }
    public function setExternalUrl(?string $externalUrl): self { $this->externalUrl = $externalUrl ?: null; return $this; }

    public function getTakedownReason(): ?string { return $this->takedownReason; }
    public function getTakenDownAt(): ?\DateTimeInterface { return $this->takenDownAt; }
    public function isTakenDown(): bool { return null !== $this->takenDownAt; }

    /** Out of sight, the reason kept for its author; restore() puts it back as it was chosen (private). */
    public function takeDown(string $reason): self
    {
        $this->takedownReason = trim($reason);
        $this->takenDownAt = new \DateTime();
        $this->setState(ThreadState::DRAFT);

        return $this;
    }

    public function restore(): self
    {
        $this->takedownReason = null;
        $this->takenDownAt = null;

        return $this;
    }

    public function getVisibility(): string
    {
        return match ($this->getState()) {
            ThreadState::PUBLISH, ThreadState::ARCHIVE => self::VISIBILITY_PUBLIC,
            ThreadState::SECRET, ThreadState::PASSWORD => self::VISIBILITY_UNLISTED,
            ThreadState::FUTURE => self::VISIBILITY_SCHEDULED,
            default => self::VISIBILITY_PRIVATE,
        };
    }

    /**
     * Public, unlisted, private, or scheduled at $at (a past or missing date
     * publishes now). A film still processing or taken down keeps its choice
     * for later: setVisibility() is only what its author asks.
     */
    public function setVisibility(string $visibility, ?\DateTimeInterface $at = null): self
    {
        if (!\in_array($visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown visibility "%s".', $visibility));
        }
        if (self::VISIBILITY_SCHEDULED === $visibility && (null === $at || $at <= new \DateTime())) {
            $visibility = self::VISIBILITY_PUBLIC;
        }

        $this->setState(match ($visibility) {
            self::VISIBILITY_PUBLIC => ThreadState::PUBLISH,
            self::VISIBILITY_UNLISTED => ThreadState::SECRET,
            self::VISIBILITY_SCHEDULED => ThreadState::FUTURE,
            default => ThreadState::DRAFT,
        });
        if (self::VISIBILITY_SCHEDULED === $visibility) {
            $this->setPublishedAt(\DateTime::createFromInterface($at));
        } elseif (\in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_UNLISTED], true) && null === $this->getPublishedAt()) {
            $this->setPublishedAt(new \DateTime());
        }

        return $this;
    }

    /** Listed on the platform: in the feeds, the search, the suggestions. */
    public function isListed(): bool
    {
        return self::VISIBILITY_PUBLIC === $this->getVisibility() && !$this->isTakenDown()
            && (null === $this->getPublishedAt() || $this->getPublishedAt() <= new \DateTime());
    }

    /** Reachable by its address (listed, or unlisted). */
    public function isReachable(): bool
    {
        return \in_array($this->getVisibility(), [self::VISIBILITY_PUBLIC, self::VISIBILITY_UNLISTED], true) && !$this->isTakenDown()
            && (null === $this->getPublishedAt() || $this->getPublishedAt() <= new \DateTime());
    }

    public function isOwnedBy(?User $user): bool
    {
        if (null === $user) {
            return false;
        }
        foreach ($this->getOwners() as $owner) {
            if ($owner === $user || ($owner?->getId() && $owner->getId() === $user->getId())) {
                return true;
            }
        }

        return $this->channel?->isOwnedBy($user) ?? false;
    }

    // ── Typesense (the "video" mapping of glitchr/typesense-bundle) ──

    public function getSearchTitle(): string { return (string) $this->getTitle(); }
    public function getSearchText(): string { return mb_substr(strip_tags((string) $this->getContent()), 0, 2000); }
    public function getSearchChannel(): string { return (string) $this->channel?->getName(); }
    public function getSearchChannelSlug(): string { return (string) $this->channel?->getSlug(); }
    public function getSearchCategory(): string { return (string) $this->category; }
    public function getSearchDuration(): int { return (int) $this->duration; }
    public function getSearchViews(): int { return $this->getTotalViews(); }
    public function getSearchPublished(): int { return (int) $this->getPublishedAt()?->getTimestamp(); }
    public function getSearchListed(): bool { return $this->isListed(); }

    /** @return list<string> */
    public function getSearchTags(): array
    {
        $tags = [];
        foreach ($this->getTags() as $tag) {
            $tags[] = trim((string) $tag) ?: (string) $tag->getSlug();
        }

        return array_values(array_filter($tags));
    }
}
