<?php

namespace Base\Video\Repository;

use Base\Entity\User;
use Base\Video\Entity\Playlist;
use Base\Video\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Playlist> */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    /** "À regarder plus tard", made the first time it is asked for. */
    public function findLater(User $user, bool $create = true): ?Playlist
    {
        $later = $this->findOneBy(['owner' => $user, 'kind' => Playlist::LATER]);
        if (!$later && $create) {
            $later = new Playlist($user, 'À regarder plus tard', Playlist::LATER);
            $this->getEntityManager()->persist($later);
        }

        return $later;
    }

    /** @return list<Playlist> a member's own lists (not "plus tard") */
    public function findOwnedBy(User $user): array
    {
        return $this->findBy(['owner' => $user, 'kind' => Playlist::LIST], ['updatedAt' => 'DESC']);
    }

    /** @return list<Playlist> the public lists of these owners, for their channel page */
    public function findPublicOf(iterable $owners, int $limit = 24): array
    {
        $owners = \is_array($owners) ? $owners : iterator_to_array($owners);
        if (!$owners) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.owner IN (:owners)')->setParameter('owners', $owners)
            ->andWhere('p.kind = :list')->setParameter('list', Playlist::LIST)
            ->andWhere('p.visibility = :public')->setParameter('public', Playlist::PUBLIC)
            ->orderBy('p.updatedAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<int> ids of the public lists a video is in (the suggestions' "same list") */
    public function findPublicIdsContaining(Video $video): array
    {
        return array_map('intval', array_column($this->createQueryBuilder('p')->select('p.id')
            ->innerJoin('p.items', 'i')->andWhere('i.video = :video')->setParameter('video', $video)
            ->andWhere('p.visibility = :public')->setParameter('public', Playlist::PUBLIC)
            ->getQuery()->getArrayResult(), 'id'));
    }

    /** @return array<int, list<int>> public list id => its video ids */
    public function findPublicMemberships(): array
    {
        $lists = [];
        foreach ($this->createQueryBuilder('p')->select('p.id AS list, IDENTITY(i.video) AS film')
            ->innerJoin('p.items', 'i')
            ->andWhere('p.visibility = :public')->setParameter('public', Playlist::PUBLIC)
            ->getQuery()->getArrayResult() as $row) {
            $lists[(int) $row['list']][] = (int) $row['film'];
        }

        return $lists;
    }
}
