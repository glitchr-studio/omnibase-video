<?php

namespace Base\Video\Repository;

use Base\Entity\User;
use Base\Enum\ThreadState;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Video;
use Base\Video\Entity\WatchEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Video> */
class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, string $class = Video::class)
    {
        parent::__construct($registry, $class);
    }

    /** Listed: public, published, not taken down, playable. */
    public function listed(string $alias = 'v'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere("$alias.state = :listedState")->setParameter('listedState', ThreadState::PUBLISH)
            ->andWhere("$alias.publishedAt IS NULL OR $alias.publishedAt <= CURRENT_TIMESTAMP()")
            ->andWhere("$alias.takenDownAt IS NULL")
            ->andWhere("$alias.processing IN (:playable)")->setParameter('playable', [Video::PROCESSING_READY, Video::PROCESSING_NONE]);
    }

    /** @return list<Video> the newest first */
    public function findLatest(int $limit = 24, int $offset = 0, ?Channel $channel = null): array
    {
        $qb = $this->listed()->orderBy('v.publishedAt', 'DESC')->addOrderBy('v.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit);
        if ($channel) {
            $qb->andWhere('v.channel = :channel')->setParameter('channel', $channel);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Video> the most seen of all time (ours and the imported views) */
    public function findPopular(int $limit = 24, ?Channel $channel = null): array
    {
        $qb = $this->listed()->addSelect('(v.views + COALESCE(v.legacyViews, 0)) AS HIDDEN total')
            ->orderBy('total', 'DESC')->addOrderBy('v.publishedAt', 'DESC')->setMaxResults($limit);
        if ($channel) {
            $qb->andWhere('v.channel = :channel')->setParameter('channel', $channel);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Video> the most watched of the last $days days (counted
     *                     sittings), the most seen of all time to fill up
     */
    public function findTrending(int $limit = 24, int $days = 7): array
    {
        $since = new \DateTime(sprintf('-%d days', $days));
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(w.video) AS id, COUNT(w.id) AS n')
            ->from(WatchEvent::class, 'w')
            ->andWhere('w.counted = true')->andWhere('w.startedAt >= :since')->setParameter('since', $since)
            ->groupBy('w.video')->orderBy('n', 'DESC')->setMaxResults($limit * 2)
            ->getQuery()->getArrayResult();

        $videos = [];
        if ($rows) {
            $byId = [];
            foreach ($this->listed()->andWhere('v.id IN (:ids)')->setParameter('ids', array_column($rows, 'id'))->getQuery()->getResult() as $video) {
                $byId[$video->getId()] = $video;
            }
            foreach ($rows as $row) {
                if (isset($byId[$row['id']])) {
                    $videos[] = $byId[$row['id']];
                }
            }
        }
        if (\count($videos) < $limit) {
            foreach ($this->findPopular($limit * 2) as $video) {
                if (!\in_array($video, $videos, true)) {
                    $videos[] = $video;
                }
            }
        }

        return \array_slice($videos, 0, $limit);
    }

    /** The video at that address, if its visitor may see it (listed, unlisted, or theirs). */
    public function findOneReachable(string $slug, ?User $viewer = null, bool $staff = false): ?Video
    {
        $video = $this->findOneBy(['slug' => $slug]);
        if (!$video instanceof Video) {
            return null;
        }

        return $video->isReachable() || $staff || $video->isOwnedBy($viewer) ? $video : null;
    }

    public function findOneByUploadId(string $uploadId): ?Video
    {
        return $this->findOneBy(['uploadId' => $uploadId]);
    }

    /** @return list<Video> a member's own, in the studio (every state but the trash) */
    public function findForStudio(User $user, ?string $visibility = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.channel', 'c')->leftJoin('c.owners', 'co')->leftJoin('v.owners', 'o')
            ->andWhere('o = :user OR co = :user')->setParameter('user', $user)
            ->orderBy('v.createdAt', 'DESC')->addOrderBy('v.id', 'DESC')
            ->distinct();
        if ($visibility) {
            $qb->andWhere('v.state = :state')->setParameter('state', match ($visibility) {
                Video::VISIBILITY_PUBLIC => ThreadState::PUBLISH,
                Video::VISIBILITY_UNLISTED => ThreadState::SECRET,
                Video::VISIBILITY_SCHEDULED => ThreadState::FUTURE,
                default => ThreadState::DRAFT,
            });
        }

        return $qb->getQuery()->getResult();
    }

    /** Bytes of originals a member has sent: what the quota counts. */
    public function sumUploads(User $user): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COALESCE(SUM(v.uploadSize), 0)')
            ->innerJoin('v.owners', 'o')->andWhere('o = :user')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Video> the new videos of the channels a member follows */
    public function findFromSubscriptions(User $user, int $limit = 48): array
    {
        return $this->listed()
            ->innerJoin('v.channel', 'c')
            ->innerJoin('Base\Video\Entity\Subscription', 's', 'WITH', 's.channel = c AND s.user = :user')->setParameter('user', $user)
            ->orderBy('v.publishedAt', 'DESC')->addOrderBy('v.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * The database's search, when Typesense is not there: every word in the
     * title, the description, the channel's name.
     *
     * @param array{category?: ?string, channel?: ?string, duration?: ?string, date?: ?string} $filters
     *
     * @return list<Video>
     */
    public function searchListed(string $query, array $filters = [], int $limit = 24, int $offset = 0): array
    {
        $qb = $this->listed()->leftJoin('v.channel', 'c')->leftJoin('v.translations', 't');
        $words = array_values(array_filter(preg_split('/\s+/u', trim($query)) ?: [], fn ($word) => mb_strlen($word) > 1));
        foreach (\array_slice($words, 0, 8) as $i => $word) {
            $qb->andWhere("t.title LIKE :w$i OR t.content LIKE :w$i OR c.name LIKE :w$i")->setParameter("w$i", '%'.addcslashes($word, '%_').'%');
        }
        self::filter($qb, $filters);

        return $qb->orderBy('v.publishedAt', 'DESC')->addOrderBy('v.id', 'DESC')->distinct()
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @param array{category?: ?string, channel?: ?string, duration?: ?string, date?: ?string} $filters */
    public static function filter(QueryBuilder $qb, array $filters): QueryBuilder
    {
        if (!empty($filters['category'])) {
            $qb->andWhere('v.category = :category')->setParameter('category', $filters['category']);
        }
        if (!empty($filters['channel'])) {
            $qb->andWhere('c.slug = :channelSlug')->setParameter('channelSlug', $filters['channel']);
        }
        [$min, $max] = self::durationBounds($filters['duration'] ?? null);
        if (null !== $min) {
            $qb->andWhere('v.duration >= :dmin')->setParameter('dmin', $min);
        }
        if (null !== $max) {
            $qb->andWhere('v.duration < :dmax')->setParameter('dmax', $max);
        }
        if ($since = self::dateSince($filters['date'] ?? null)) {
            $qb->andWhere('v.publishedAt >= :since')->setParameter('since', $since);
        }

        return $qb;
    }

    /** short: under 4 minutes; medium: 4 to 20; long: over 20. @return array{0: ?int, 1: ?int} */
    public static function durationBounds(?string $duration): array
    {
        return match ($duration) {
            'short' => [null, 240],
            'medium' => [240, 1200],
            'long' => [1200, null],
            default => [null, null],
        };
    }

    public static function dateSince(?string $date): ?\DateTime
    {
        return match ($date) {
            'day' => new \DateTime('-1 day'),
            'week' => new \DateTime('-7 days'),
            'month' => new \DateTime('-1 month'),
            'year' => new \DateTime('-1 year'),
            default => null,
        };
    }

    /** @return array<string, int> processing state => videos, for the back office */
    public function countByProcessing(): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('v')->select('v.processing AS p, COUNT(v.id) AS n')->groupBy('v.processing')->getQuery()->getArrayResult() as $row) {
            $counts[$row['p']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return list<Video> */
    public function findProcessing(int $limit = 10): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.processing IN (:states)')->setParameter('states', [Video::PROCESSING_UPLOADED, Video::PROCESSING_RUNNING, Video::PROCESSING_FAILED])
            ->orderBy('v.updatedAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Video> listed, by id: what the suggestions compute over */
    public function findAllListed(int $limit = 5000): array
    {
        return $this->listed()->leftJoin('v.channel', 'c')->addSelect('c')->orderBy('v.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @param list<int> $ids @return list<Video> listed, in the order of $ids */
    public function findListedByIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $byId = [];
        foreach ($this->listed()->andWhere('v.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $video) {
            $byId[$video->getId()] = $video;
        }

        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }
}
