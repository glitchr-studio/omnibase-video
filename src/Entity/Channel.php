<?php

namespace Base\Video\Entity;

use Base\Database\Attribute\Timestamp;
use Base\Entity\User;
use Base\Video\Repository\ChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A member's channel - or a broadcaster's: its name, its address, its
 * banner and picture, what it says of itself, the shelves its page shows,
 * its subscribers. A member gets one the first time they publish; owners
 * share it the way omnibase's threads share theirs (several accounts).
 */
#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[ORM\Table(name: 'video_channel')]
class Channel
{
    /** The shelves a channel page can show, in the order it shows them. */
    public const SHELVES = ['latest', 'popular', 'playlists'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 80, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9][a-z0-9-]{1,78}$/')]
    protected ?string $slug = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    protected ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000)]
    protected ?string $description = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'video_channel_owner')]
    protected Collection $owners;

    /** Paths under the public directory (the channel's pictures, see Service\Pictures), or absolute addresses. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $avatar = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $banner = null;

    #[ORM\Column(type: 'json')]
    protected array $shelves = self::SHELVES;

    /** Kept by Subscriptions, so a page never counts its rows. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    protected int $subscribers = 0;

    #[ORM\Column(type: 'datetime')]
    #[Timestamp(on: 'create')]
    protected ?\DateTimeInterface $createdAt = null;

    /** @var Collection<int, Video> */
    #[ORM\OneToMany(targetEntity: Video::class, mappedBy: 'channel')]
    protected Collection $videos;

    public function __construct(?string $name = null, ?User $owner = null, ?string $slug = null)
    {
        $this->owners = new ArrayCollection();
        $this->videos = new ArrayCollection();
        $this->name = $name;
        $this->slug = $slug ?? (null !== $name ? self::slugify($name) : null);
        $this->createdAt = new \DateTime();
        if ($owner) {
            $this->addOwner($owner);
        }
    }

    public static function slugify(string $text): string
    {
        $slug = strtolower((string) (new AsciiSlugger())->slug($text));

        return '' !== $slug ? mb_substr($slug, 0, 78) : 'chaine';
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int { return $this->id; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = $slug; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = null !== $name ? trim($name) : null; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description ? trim($description) : null; return $this; }

    /** @return Collection<int, User> */
    public function getOwners(): Collection { return $this->owners; }

    public function getOwner(): ?User { return $this->owners->first() ?: null; }

    public function addOwner(User $owner): self
    {
        if (!$this->owners->contains($owner)) {
            $this->owners->add($owner);
        }

        return $this;
    }

    public function removeOwner(User $owner): self
    {
        $this->owners->removeElement($owner);

        return $this;
    }

    public function isOwnedBy(?User $user): bool
    {
        if (null === $user) {
            return false;
        }
        foreach ($this->owners as $owner) {
            if ($owner === $user || ($owner->getId() && $owner->getId() === $user->getId())) {
                return true;
            }
        }

        return false;
    }

    public function getAvatar(): ?string { return $this->avatar; }
    public function setAvatar(?string $avatar): self { $this->avatar = $avatar ?: null; return $this; }

    public function getBanner(): ?string { return $this->banner; }
    public function setBanner(?string $banner): self { $this->banner = $banner ?: null; return $this; }

    /** @return list<string> */
    public function getShelves(): array { return array_values(array_intersect($this->shelves, self::SHELVES)); }
    public function setShelves(array $shelves): self { $this->shelves = array_values(array_intersect($shelves, self::SHELVES)); return $this; }

    public function getSubscribers(): int { return $this->subscribers; }
    public function setSubscribers(int $subscribers): self { $this->subscribers = max(0, $subscribers); return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }

    /** @return Collection<int, Video> */
    public function getVideos(): Collection { return $this->videos; }

    /** Two letters for a channel without a picture. */
    public function getInitials(): string
    {
        $words = preg_split('/[\s\-_]+/u', trim((string) $this->name)) ?: [];
        $letters = array_map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)), array_filter($words));

        return implode('', \array_slice($letters, 0, 2)) ?: '?';
    }
}
