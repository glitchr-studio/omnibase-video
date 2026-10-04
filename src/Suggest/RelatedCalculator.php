<?php

namespace Base\Video\Suggest;

use Base\Video\Entity\Related;
use Base\Video\Entity\Video;
use Base\Video\Repository\PlaylistRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The Related table, computed again at night (video:related). For two
 * listed films A and B, B's score after A:
 *
 *     3   · same channel, or same artist (an AffinityInterface's 3.0 groups)
 *   + 2   · same public list, or same episode (its 2.0 groups)
 *   + 1   · each keyword in common (three at most)
 *   + 2.5 · co-watching (the cosine of their audiences over 90 days)
 *   + 0.5 · B's freshness (e^(-age/30 days))
 *   + 0.5 · B's popularity (log of its views against the most seen)
 *
 * A strength shared twice counts once (same channel and same artist: 3).
 * Only pairs that share something are scored - a group shared by more than
 * MAX_GROUP films says nothing and only scores pairs found otherwise. Each
 * film keeps its NEIGHBOURS best, no more than video.suggest.per_channel
 * from one channel.
 */
class RelatedCalculator
{
    public const NEIGHBOURS = 24;
    public const MAX_GROUP = 400;

    /** @param iterable<AffinityInterface> $affinities */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoRepository $videos,
        private readonly PlaylistRepository $playlists,
        private readonly WatchEventRepository $events,
        #[AutowireIterator('video.affinity')] private readonly iterable $affinities = [],
        #[Autowire('%video.suggest.per_channel%')] private readonly int $perChannel = 2,
    ) {
    }

    /** @return int the rows written */
    public function compute(?\DateTimeInterface $now = null): int
    {
        $videos = $this->videos->findAllListed();
        $groups = $this->groups($videos);
        $audiences = $this->audiences();
        $rows = $this->score($videos, $groups, $audiences, $now ?? new \DateTime());

        $connection = $this->entityManager->getConnection();
        $metadata = $this->entityManager->getClassMetadata(Related::class);
        $table = $connection->quoteSingleIdentifier($metadata->getTableName());
        $video = $connection->quoteSingleIdentifier($metadata->getSingleAssociationJoinColumnName('video'));
        $related = $connection->quoteSingleIdentifier($metadata->getSingleAssociationJoinColumnName('related'));
        $score = $connection->quoteSingleIdentifier($metadata->getColumnName('score'));
        $computed = $connection->quoteSingleIdentifier($metadata->getColumnName('computedAt'));

        $connection->beginTransaction();
        try {
            $connection->executeStatement("DELETE FROM $table");
            $at = ($now ?? new \DateTime())->format('Y-m-d H:i:s');
            foreach (array_chunk($rows, 500) as $chunk) {
                $values = [];
                $parameters = [];
                foreach ($chunk as [$a, $b, $s]) {
                    $values[] = '(?, ?, ?, ?)';
                    array_push($parameters, $a, $b, $s, $at);
                }
                $connection->executeStatement("INSERT INTO $table ($video, $related, $score, $computed) VALUES ".implode(', ', $values), $parameters);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return \count($rows);
    }

    /**
     * The scoring itself, on plain data - tested without a database.
     *
     * @param list<Video>                         $videos
     * @param array<int, array<string, float>>    $groups    video id => group => weight
     * @param array<int, array<string, bool>>     $audiences video id => viewer => true
     *
     * @return list<array{0: int, 1: int, 2: float}> [video id, neighbour id, score]
     */
    public function score(array $videos, array $groups, array $audiences, \DateTimeInterface $now): array
    {
        $byId = [];
        foreach ($videos as $video) {
            $byId[$video->getId()] = $video;
        }
        $maxViews = 1;
        foreach ($videos as $video) {
            $maxViews = max($maxViews, $video->getTotalViews());
        }

        // Keywords and groups indexed: a key => the films that have it.
        $keywords = [];
        $index = [];
        foreach ($videos as $video) {
            $id = $video->getId();
            $keywords[$id] = array_flip(array_map(fn ($k) => mb_strtolower($k), $video->getSearchTags()));
            foreach (array_keys($groups[$id] ?? []) as $key) {
                $index[$key][] = $id;
            }
            foreach (array_keys($keywords[$id]) as $keyword) {
                $index['kw:'.$keyword][] = $id;
            }
        }
        $watchers = [];
        foreach ($audiences as $id => $viewers) {
            foreach (array_keys($viewers) as $viewer) {
                $watchers[$viewer][] = $id;
            }
        }

        $rows = [];
        foreach ($videos as $video) {
            $a = $video->getId();
            $candidates = [];
            foreach (array_keys($groups[$a] ?? []) as $key) {
                if (\count($index[$key]) <= self::MAX_GROUP) {
                    foreach ($index[$key] as $b) {
                        $candidates[$b] = true;
                    }
                }
            }
            foreach (array_keys($keywords[$a]) as $keyword) {
                if (\count($index['kw:'.$keyword]) <= self::MAX_GROUP) {
                    foreach ($index['kw:'.$keyword] as $b) {
                        $candidates[$b] = true;
                    }
                }
            }
            foreach (array_keys($audiences[$a] ?? []) as $viewer) {
                foreach ($watchers[$viewer] as $b) {
                    $candidates[$b] = true;
                }
            }
            unset($candidates[$a]);

            $scores = [];
            foreach (array_keys($candidates) as $b) {
                if (!isset($byId[$b])) {
                    continue;
                }
                $scores[$b] = $this->pair($video, $byId[$b], $groups[$a] ?? [], $groups[$b] ?? [], $keywords[$a], $keywords[$b], $audiences[$a] ?? [], $audiences[$b] ?? [], $maxViews, $now);
            }
            arsort($scores);

            $perChannel = [];
            $kept = 0;
            foreach ($scores as $b => $score) {
                $channel = $byId[$b]->getChannel()?->getId() ?? 0;
                if ($this->perChannel > 0 && ($perChannel[$channel] ?? 0) >= $this->perChannel) {
                    continue;
                }
                $perChannel[$channel] = ($perChannel[$channel] ?? 0) + 1;
                $rows[] = [$a, $b, round($score, 4)];
                if (++$kept >= self::NEIGHBOURS) {
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, float> $groupsA
     * @param array<string, float> $groupsB
     * @param array<string, int>   $keywordsA
     * @param array<string, int>   $keywordsB
     * @param array<string, bool>  $audienceA
     * @param array<string, bool>  $audienceB
     */
    public function pair(Video $a, Video $b, array $groupsA, array $groupsB, array $keywordsA, array $keywordsB, array $audienceA, array $audienceB, int $maxViews, \DateTimeInterface $now): float
    {
        // Each strength once: the distinct weights of the groups they share.
        $shared = array_unique(array_map('floatval', array_intersect_key($groupsA, $groupsB)));
        $score = array_sum($shared);
        $score += 1.0 * min(3, \count(array_intersect_key($keywordsA, $keywordsB)));
        if ($audienceA && $audienceB) {
            $both = \count(array_intersect_key($audienceA, $audienceB));
            $score += 2.5 * ($both / sqrt(\count($audienceA) * \count($audienceB)));
        }
        $published = $b->getPublishedAt() ?? $b->getCreatedAt();
        if ($published) {
            $days = max(0, ($now->getTimestamp() - $published->getTimestamp()) / 86400);
            $score += 0.5 * exp(-$days / 30);
        }
        $score += 0.5 * (log10(1 + $b->getTotalViews()) / log10(1 + $maxViews));

        return $score;
    }

    /**
     * The groups of each film: its channel (3), its public lists (2), and
     * whatever the affinities add.
     *
     * @param list<Video> $videos
     *
     * @return array<int, array<string, float>>
     */
    public function groups(array $videos): array
    {
        $groups = [];
        foreach ($videos as $video) {
            if ($channel = $video->getChannel()) {
                $groups[$video->getId()]['channel:'.$channel->getId()] = 3.0;
            }
        }
        foreach ($this->playlists->findPublicMemberships() as $list => $ids) {
            foreach ($ids as $id) {
                $groups[$id]['list:'.$list] = 2.0;
            }
        }
        foreach ($this->affinities as $affinity) {
            foreach ($affinity->groups($videos) as $id => $more) {
                $groups[$id] = ($groups[$id] ?? []) + $more;
            }
        }

        return $groups;
    }

    /** @return array<int, array<string, bool>> video id => its viewers over 90 days */
    private function audiences(): array
    {
        $audiences = [];
        foreach ($this->events->findCoWatching() as $viewer => $ids) {
            foreach ($ids as $id) {
                $audiences[$id][$viewer] = true;
            }
        }

        return $audiences;
    }
}
