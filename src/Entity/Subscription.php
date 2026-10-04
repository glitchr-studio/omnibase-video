<?php

namespace Base\Video\Entity;

use Base\Entity\User;
use Base\Video\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A member following a channel: its new videos in their feed (/abonnements). */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'video_subscription')]
#[ORM\UniqueConstraint(name: 'video_subscription_unique', columns: ['user_id', 'channel_id'])]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    protected User $user;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(name: 'channel_id', nullable: false, onDelete: 'CASCADE')]
    protected Channel $channel;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $createdAt;

    public function __construct(User $user, Channel $channel)
    {
        $this->user = $user;
        $this->channel = $channel;
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getChannel(): Channel { return $this->channel; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
