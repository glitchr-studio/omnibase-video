<?php

namespace Base\Video\Repository;

use Base\Video\Entity\Related;
use Base\Video\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Related> */
class RelatedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Related::class);
    }

    /** @return list<Video> a film's neighbours, the closest first, still listed */
    public function findFor(Video $video, int $limit = 12): array
    {
        $rows = $this->createQueryBuilder('r')->innerJoin('r.related', 'v')->addSelect('v')
            ->andWhere('r.video = :video')->setParameter('video', $video)
            ->orderBy('r.score', 'DESC')->setMaxResults($limit * 2)
            ->getQuery()->getResult();

        return \array_slice(array_values(array_filter(array_map(fn (Related $r) => $r->getRelated(), $rows), fn (Video $v) => $v->isListed())), 0, $limit);
    }

    /** @param list<int> $videoIds @return array<int, float> neighbour id => summed score over $videoIds */
    public function scoresFor(array $videoIds, int $limit = 100): array
    {
        if (!$videoIds) {
            return [];
        }
        $scores = [];
        foreach ($this->createQueryBuilder('r')->select('IDENTITY(r.related) AS id, SUM(r.score) AS s')
            ->andWhere('r.video IN (:ids)')->setParameter('ids', $videoIds)
            ->groupBy('r.related')->orderBy('s', 'DESC')->setMaxResults($limit)
            ->getQuery()->getArrayResult() as $row) {
            $scores[(int) $row['id']] = (float) $row['s'];
        }

        return $scores;
    }

    public function deleteFor(Video $video): void
    {
        $this->createQueryBuilder('r')->delete()->andWhere('r.video = :video')->setParameter('video', $video)->getQuery()->execute();
    }

    public function deleteAll(): void
    {
        $this->createQueryBuilder('r')->delete()->getQuery()->execute();
    }
}
