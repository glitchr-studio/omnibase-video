<?php

namespace Base\Video\Repository;

use Base\Entity\User;
use Base\Video\Entity\Channel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Channel> */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    /** The first channel a member owns. */
    public function findOneOwnedBy(User $user): ?Channel
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.owners', 'o')->andWhere('o = :user')->setParameter('user', $user)
            ->orderBy('c.id', 'ASC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** A free address for $name: "ma-chaine", then "ma-chaine-2"... */
    public function freeSlug(string $name): string
    {
        $base = Channel::slugify($name);
        $slug = $base;
        for ($i = 2; null !== $this->findOneBy(['slug' => $slug]); ++$i) {
            $slug = mb_substr($base, 0, 74).'-'.$i;
        }

        return $slug;
    }

    /** @return list<Channel> the most followed */
    public function findPopular(int $limit = 12): array
    {
        return $this->createQueryBuilder('c')->orderBy('c.subscribers', 'DESC')->addOrderBy('c.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
