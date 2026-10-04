<?php

namespace Base\Video\Repository;

use Base\Entity\User;
use Base\Video\Entity\Video;
use Base\Video\Entity\WatchEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WatchEvent> */
class WatchEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchEvent::class);
    }

    public function findOneByToken(Video $video, string $token): ?WatchEvent
    {
        return $this->findOneBy(['video' => $video, 'token' => $token]);
    }

    /**
     * A member's history: each video once, the last watched first.
     *
     * @return list<array{video: Video, position: float, at: \DateTimeInterface}>
     */
    public function findHistory(User $user, int $limit = 60): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('IDENTITY(w.video) AS id, MAX(w.updatedAt) AS at, MAX(w.position) AS position')
            ->andWhere('w.user = :user')->setParameter('user', $user)
            ->groupBy('w.video')->orderBy('at', 'DESC')->setMaxResults($limit)
            ->getQuery()->getArrayResult();
        if (!$rows) {
            return [];
        }
        $videos = [];
        foreach ($this->getEntityManager()->getRepository(Video::class)->findBy(['id' => array_column($rows, 'id')]) as $video) {
            $videos[$video->getId()] = $video;
        }
        $history = [];
        foreach ($rows as $row) {
            if (isset($videos[$row['id']]) && $videos[$row['id']]->isReachable()) {
                $history[] = ['video' => $videos[$row['id']], 'position' => (float) $row['position'], 'at' => new \DateTime($row['at'])];
            }
        }

        return $history;
    }

    /** @return list<int> the videos a member (or a visitor) watched, the last first */
    public function findWatchedIds(?User $user, ?string $visitor = null, int $limit = 200): array
    {
        if (!$user && !$visitor) {
            return [];
        }
        $qb = $this->createQueryBuilder('w')->select('IDENTITY(w.video) AS id, MAX(w.updatedAt) AS at')
            ->groupBy('w.video')->orderBy('at', 'DESC')->setMaxResults($limit);
        $user ? $qb->andWhere('w.user = :user')->setParameter('user', $user) : $qb->andWhere('w.visitor = :visitor')->setParameter('visitor', $visitor);

        return array_map('intval', array_column($qb->getQuery()->getArrayResult(), 'id'));
    }

    /** Where a member stopped last time, in seconds (null: never, or near the end). */
    public function findResume(User $user, Video $video): ?float
    {
        $last = $this->findOneBy(['user' => $user, 'video' => $video], ['updatedAt' => 'DESC']);
        if (!$last || $last->isCompleted() || $last->getPosition() < 5) {
            return null;
        }

        return $last->getPosition();
    }

    public function clearHistory(User $user): int
    {
        return (int) $this->createQueryBuilder('w')->update()->set('w.user', 'NULL')
            ->andWhere('w.user = :user')->setParameter('user', $user)
            ->getQuery()->execute();
    }

    public function removeFromHistory(User $user, Video $video): int
    {
        return (int) $this->createQueryBuilder('w')->update()->set('w.user', 'NULL')
            ->andWhere('w.user = :user')->setParameter('user', $user)
            ->andWhere('w.video = :video')->setParameter('video', $video)
            ->getQuery()->execute();
    }

    /** @return array<int, int> video id => views counted and not yet added to Video::$views */
    public function countUnflushed(int $limit = 5000): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('w')->select('IDENTITY(w.video) AS id, COUNT(w.id) AS n')
            ->andWhere('w.counted = true')->andWhere('w.flushed = false')
            ->groupBy('w.video')->setMaxResults($limit)
            ->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @param list<int> $videoIds */
    public function markFlushed(array $videoIds): int
    {
        if (!$videoIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('w')->update()->set('w.flushed', 'true')
            ->andWhere('w.counted = true')->andWhere('w.flushed = false')
            ->andWhere('w.video IN (:ids)')->setParameter('ids', $videoIds)
            ->getQuery()->execute();
    }

    /**
     * Who watched what, for the suggestions' co-watching: viewer key (the
     * account, else the visitor) => the videos they watched enough to count.
     *
     * @return array<string, list<int>>
     */
    public function findCoWatching(int $days = 90, int $limit = 200000): array
    {
        $viewers = [];
        foreach ($this->createQueryBuilder('w')->select('IDENTITY(w.user) AS u, w.visitor AS visitor, IDENTITY(w.video) AS v')
            ->andWhere('w.counted = true')->andWhere('w.startedAt >= :since')->setParameter('since', new \DateTime(sprintf('-%d days', $days)))
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult() as $row) {
            $key = $row['u'] ? 'u'.$row['u'] : 'v'.$row['visitor'];
            $viewers[$key][(int) $row['v']] = true;
        }

        return array_map(fn (array $videos) => array_keys($videos), $viewers);
    }

    /**
     * The studio's figures for one film.
     *
     * @return array{views: int, sittings: int, average: float, completion: float, retention: list<float>, sources: array<string, int>, days: array<string, int>}
     */
    public function stats(Video $video, int $days = 30, int $buckets = 20): array
    {
        $rows = $this->createQueryBuilder('w')->select('w.position AS position, w.watched AS watched, w.counted AS counted, w.source AS source, w.startedAt AS at, w.completed AS completed')
            ->andWhere('w.video = :video')->setParameter('video', $video)
            ->getQuery()->getArrayResult();

        return self::figures($rows, (int) $video->getDuration(), $days, $buckets);
    }

    /**
     * The figures from raw sittings - separate, so they are tested without a
     * database. The retention curve: for each twentieth of the film, the
     * share of the sittings that reached it.
     *
     * @param list<array{position: float, watched: float, counted: bool, source: string, at: \DateTimeInterface, completed?: bool}> $rows
     *
     * @return array{views: int, sittings: int, average: float, completion: float, retention: list<float>, sources: array<string, int>, days: array<string, int>}
     */
    public static function figures(array $rows, int $duration, int $days = 30, int $buckets = 20, ?\DateTimeInterface $today = null): array
    {
        $today = \DateTimeImmutable::createFromInterface($today ?? new \DateTime())->setTime(0, 0);
        $daily = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $daily[$today->modify("-$i days")->format('Y-m-d')] = 0;
        }
        $sittings = \count($rows);
        $views = 0;
        $watched = 0.0;
        $sources = [];
        $reached = array_fill(0, $buckets, 0);
        $completed = 0;
        foreach ($rows as $row) {
            $watched += (float) $row['watched'];
            if ($row['counted']) {
                ++$views;
                $sources[$row['source']] = ($sources[$row['source']] ?? 0) + 1;
                $day = $row['at'] instanceof \DateTimeInterface ? $row['at']->format('Y-m-d') : substr((string) $row['at'], 0, 10);
                if (isset($daily[$day])) {
                    ++$daily[$day];
                }
            }
            if (!empty($row['completed']) || ($duration > 0 && (float) $row['position'] >= $duration - 1)) {
                ++$completed;
            }
            if ($duration > 0) {
                $fraction = min(1.0, (float) $row['position'] / $duration);
                for ($b = 0; $b < $buckets; ++$b) {
                    if ($fraction >= $b / $buckets) {
                        ++$reached[$b];
                    }
                }
            }
        }
        arsort($sources);

        return [
            'views' => $views,
            'sittings' => $sittings,
            'average' => $sittings ? round($watched / $sittings, 1) : 0.0,
            'completion' => $sittings ? round($completed / $sittings, 3) : 0.0,
            'retention' => array_map(fn ($n) => $sittings ? round($n / $sittings, 3) : 0.0, $reached),
            'sources' => $sources,
            'days' => $daily,
        ];
    }

    /** Counted views over the last $days days, all videos: the dashboard's figure. */
    public function countViewsSince(int $days = 30): int
    {
        return (int) $this->createQueryBuilder('w')->select('COUNT(w.id)')
            ->andWhere('w.counted = true')->andWhere('w.startedAt >= :since')->setParameter('since', new \DateTime(sprintf('-%d days', $days)))
            ->getQuery()->getSingleScalarResult();
    }
}
