<?php

namespace Base\Video\Repository;

use Base\Entity\User;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Subscription> */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findOneFor(User $user, Channel $channel): ?Subscription
    {
        return $this->findOneBy(['user' => $user, 'channel' => $channel]);
    }

    public function countFor(Channel $channel): int
    {
        return $this->count(['channel' => $channel]);
    }

    /** @return list<Channel> the channels a member follows */
    public function findChannels(User $user): array
    {
        return array_map(fn (Subscription $s) => $s->getChannel(), $this->findBy(['user' => $user], ['createdAt' => 'DESC']));
    }

    /** @return list<Subscription> a channel's subscribers, the newest first */
    public function findSubscribers(Channel $channel, int $limit = 100): array
    {
        return $this->findBy(['channel' => $channel], ['createdAt' => 'DESC'], $limit);
    }
}
