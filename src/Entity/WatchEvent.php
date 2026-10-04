<?php

namespace Base\Video\Entity;

use Base\Entity\User;
use Base\Video\Repository\WatchEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One sitting in front of a film: who (an account, or a visitor's hashed
 * id), from where (home, search, channel, playlist, suggestion, another
 * site), how far the player went and how many seconds were really watched.
 * It becomes a view once enough was watched (Service\Views), and is added
 * to Video::$views by the next flush (video:views:flush, from cron). The
 * same rows make a member's history and the studio's statistics.
 */
#[ORM\Entity(repositoryClass: WatchEventRepository::class)]
#[ORM\Table(name: 'video_watch')]
#[ORM\Index(columns: ['video_id', 'startedAt'], name: 'video_watch_video_idx')]
#[ORM\Index(columns: ['user_id', 'updatedAt'], name: 'video_watch_user_idx')]
#[ORM\Index(columns: ['counted', 'flushed'], name: 'video_watch_flush_idx')]
#[ORM\Index(columns: ['token'], name: 'video_watch_token_idx')]
class WatchEvent
{
    public const SOURCES = ['home', 'search', 'channel', 'playlist', 'suggest', 'subscriptions', 'history', 'external', 'direct'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', nullable: false, onDelete: 'CASCADE')]
    protected Video $video;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $user = null;

    /** The player's id for this sitting (random, from the page): the beacons of one sitting land on one row. */
    #[ORM\Column(length: 40)]
    protected string $token;

    /** A visitor's hashed id (never the address itself). */
    #[ORM\Column(length: 64)]
    protected string $visitor;

    #[ORM\Column(length: 16, options: ['default' => 'direct'])]
    protected string $source = 'direct';

    /** The other site it came from, when it did. */
    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $referrer = null;

    /** The furthest point reached, in seconds. */
    #[ORM\Column(type: 'float', options: ['default' => 0])]
    protected float $position = 0.0;

    /** Seconds actually played (a seek forward is not watching). */
    #[ORM\Column(type: 'float', options: ['default' => 0])]
    protected float $watched = 0.0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $counted = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $flushed = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $completed = false;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $startedAt;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $updatedAt;

    public function __construct(Video $video, string $token, string $visitor, ?User $user = null, string $source = 'direct', ?string $referrer = null)
    {
        $this->video = $video;
        $this->token = mb_substr($token, 0, 40);
        $this->visitor = mb_substr($visitor, 0, 64);
        $this->user = $user;
        $this->source = \in_array($source, self::SOURCES, true) ? $source : 'direct';
        $this->referrer = null !== $referrer ? mb_substr($referrer, 0, 120) : null;
        $this->startedAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): Video { return $this->video; }
    public function getUser(): ?User { return $this->user; }
    public function getToken(): string { return $this->token; }
    public function getVisitor(): string { return $this->visitor; }
    public function getSource(): string { return $this->source; }
    public function getReferrer(): ?string { return $this->referrer; }

    public function getPosition(): float { return $this->position; }
    public function getWatched(): float { return $this->watched; }

    /**
     * What a beacon says: where the player is, and how many seconds it has
     * played in all this sitting. Neither goes back; a jump in "watched"
     * larger than the time since the last beacon is a forged one, kept to
     * that time.
     */
    public function progress(float $position, float $watched, ?\DateTimeInterface $now = null): self
    {
        $now ??= new \DateTime();
        $elapsed = max(1.0, (float) ($now->getTimestamp() - $this->updatedAt->getTimestamp()) + 1.0);
        $duration = (float) ($this->video->getDuration() ?? 0);
        $position = max(0.0, $duration > 0 ? min($position, $duration) : $position);
        $watched = max(0.0, $watched);

        $this->position = max($this->position, $position);
        $this->watched = max($this->watched, min($watched, $this->watched + $elapsed * 2.5));
        if ($duration > 0) {
            $this->watched = min($this->watched, $duration * 3);
        }
        $this->updatedAt = \DateTime::createFromInterface($now);

        return $this;
    }

    public function isCounted(): bool { return $this->counted; }
    public function count(): self { $this->counted = true; return $this; }

    public function isFlushed(): bool { return $this->flushed; }
    public function flush(): self { $this->flushed = true; return $this; }

    public function isCompleted(): bool { return $this->completed; }
    public function complete(): self { $this->completed = true; return $this; }

    public function getStartedAt(): \DateTimeInterface { return $this->startedAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
}
