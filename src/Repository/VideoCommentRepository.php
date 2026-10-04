<?php

namespace Base\Video\Repository;

use Base\Enum\CommentState;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<VideoComment> */
class VideoCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoComment::class);
    }

    /** @return list<VideoComment> the comments online under a film (not the replies), the pinned one first, then the newest */
    public function findVisible(Video $video, int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.thread = :video')->setParameter('video', $video)
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.state = :approved')->setParameter('approved', CommentState::APPROVED)
            ->orderBy('c.pinned', 'DESC')->addOrderBy('c.createdAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countVisible(Video $video): int
    {
        return (int) $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->andWhere('c.thread = :video')->setParameter('video', $video)
            ->andWhere('c.state = :approved')->setParameter('approved', CommentState::APPROVED)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<VideoComment> every comment of a film, for its author's studio */
    public function findForVideo(Video $video): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.thread = :video')->setParameter('video', $video)
            ->andWhere('c.state != :spam')->setParameter('spam', CommentState::SPAM)
            ->orderBy('c.pinned', 'DESC')->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->andWhere('c.state = :pending')->setParameter('pending', CommentState::PENDING)
            ->getQuery()->getSingleScalarResult();
    }

    public function unpinAll(Video $video): void
    {
        $this->createQueryBuilder('c')->update()->set('c.pinned', 'false')
            ->andWhere('c.thread = :video')->setParameter('video', $video)
            ->getQuery()->execute();
    }
}
