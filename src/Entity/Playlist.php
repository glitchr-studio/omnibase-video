<?php

namespace Base\Video\Entity;

use Base\Entity\User;
use Base\Video\Repository\PlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A list of videos a member keeps: their own lists (public, unlisted or
 * private) and "À regarder plus tard" (one a member, always private). A
 * channel shows its owners' public lists. The history is not a list: it is
 * the member's WatchEvents.
 */
#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'video_playlist')]
class Playlist
{
    public const LIST = 'list';
    public const LATER = 'later';

    public const PUBLIC = 'public';
    public const UNLISTED = 'unlisted';
    public const PRIVATE = 'private';
    public const VISIBILITIES = [self::PUBLIC, self::UNLISTED, self::PRIVATE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /** Random: an unlisted list is reachable by its address only. */
    #[ORM\Column(length: 16, unique: true)]
    protected string $slug;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    protected ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    protected ?string $description = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected User $owner;

    #[ORM\Column(length: 8, options: ['default' => self::LIST])]
    protected string $kind = self::LIST;

    #[ORM\Column(length: 8, options: ['default' => self::PRIVATE])]
    protected string $visibility = self::PRIVATE;

    /** @var Collection<int, PlaylistItem> */
    #[ORM\OneToMany(targetEntity: PlaylistItem::class, mappedBy: 'playlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    protected Collection $items;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $updatedAt;

    public function __construct(User $owner, ?string $title = null, string $kind = self::LIST)
    {
        $this->owner = $owner;
        $this->title = $title;
        $this->kind = self::LATER === $kind ? self::LATER : self::LIST;
        $this->slug = substr(strtr(base64_encode(random_bytes(12)), '+/', '-_'), 0, 16);
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = null !== $title ? trim($title) : null; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description ? trim($description) : null; return $this; }

    public function getOwner(): User { return $this->owner; }
    public function isOwnedBy(?User $user): bool { return null !== $user && ($user === $this->owner || ($user->getId() && $user->getId() === $this->owner->getId())); }

    public function getKind(): string { return $this->kind; }
    public function isLater(): bool { return self::LATER === $this->kind; }

    public function getVisibility(): string { return $this->isLater() ? self::PRIVATE : $this->visibility; }
    public function setVisibility(string $visibility): self
    {
        if (!\in_array($visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown visibility "%s".', $visibility));
        }
        $this->visibility = $visibility;

        return $this;
    }

    public function isPublic(): bool { return self::PUBLIC === $this->getVisibility(); }
    public function isReachableBy(?User $user): bool { return self::PRIVATE !== $this->getVisibility() || $this->isOwnedBy($user); }

    /** @return Collection<int, PlaylistItem> */
    public function getItems(): Collection { return $this->items; }

    /** @return list<Video> the videos still reachable, in order */
    public function getVideos(): array
    {
        return array_values(array_filter(array_map(fn (PlaylistItem $item) => $item->getVideo(), $this->items->toArray()), fn (Video $video) => $video->isReachable()));
    }

    public function has(Video $video): bool
    {
        return null !== $this->find($video);
    }

    public function find(Video $video): ?PlaylistItem
    {
        foreach ($this->items as $item) {
            if ($item->getVideo() === $video || ($video->getId() && $item->getVideo()->getId() === $video->getId())) {
                return $item;
            }
        }

        return null;
    }

    /** At the end ("plus tard": at the start, the newest first). */
    public function add(Video $video): self
    {
        if ($this->has($video)) {
            return $this;
        }
        $positions = array_map(fn (PlaylistItem $item) => $item->getPosition(), $this->items->toArray());
        $position = $this->isLater() ? (($positions ? min($positions) : 0) - 1) : (($positions ? max($positions) : 0) + 1);
        $this->items->add(new PlaylistItem($this, $video, $position));
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function remove(Video $video): self
    {
        if ($item = $this->find($video)) {
            $this->items->removeElement($item);
            $this->updatedAt = new \DateTime();
        }

        return $this;
    }

    /** @param list<int> $videoIds the new order, by video id; the others keep theirs after */
    public function reorder(array $videoIds): self
    {
        $rank = array_flip(array_values($videoIds));
        $items = $this->items->toArray();
        usort($items, fn (PlaylistItem $a, PlaylistItem $b) => [$rank[$a->getVideo()->getId()] ?? \PHP_INT_MAX, $a->getPosition()] <=> [$rank[$b->getVideo()->getId()] ?? \PHP_INT_MAX, $b->getPosition()]);
        foreach ($items as $i => $item) {
            $item->setPosition($i + 1);
        }
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function getFirstVideo(): ?Video { return $this->getVideos()[0] ?? null; }

    /** The one after $video in the list, for "à suivre". */
    public function next(Video $video): ?Video
    {
        $videos = $this->getVideos();
        foreach ($videos as $i => $candidate) {
            if ($candidate === $video || $candidate->getId() === $video->getId()) {
                return $videos[$i + 1] ?? null;
            }
        }

        return null;
    }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
}
