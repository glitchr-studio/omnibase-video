<?php

namespace Base\Video\Suggest;

use Base\Entity\User;
use Base\Video\Entity\Video;
use Base\Video\Repository\RelatedRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The suggestions computed here: a film's neighbours from the Related
 * table (RelatedCalculator, at night); for a member or a visitor, the
 * neighbours of what they watched last, summed, what they already saw left
 * out, and one in five taken from the trends so the feed is not only more
 * of the same. No more than video.suggest.per_channel films of one channel
 * in a list.
 */
class LocalSuggest implements SuggestInterface
{
    public function __construct(
        private readonly RelatedRepository $related,
        private readonly VideoRepository $videos,
        private readonly WatchEventRepository $events,
        #[Autowire('%video.suggest.per_channel%')] private readonly int $perChannel = 2,
    ) {
    }

    public function feedback(string $type, User $user, Video $video): void
    {
        // Read from the watch events and the likes at night: nothing to send.
    }

    public function recommend(User $user, int $limit = 24): array
    {
        return $this->session($this->events->findWatchedIds($user, null, 50), $limit);
    }

    public function session(array $videoIds, int $limit = 24): array
    {
        $seen = array_flip($videoIds);
        $trending = array_values(array_filter($this->videos->findTrending($limit * 2), fn (Video $v) => !isset($seen[$v->getId()])));
        // A feed is longer than "à suivre": three times the channel's share there.
        $per = $this->perChannel > 0 ? $this->perChannel * 3 : 0;
        if (!$videoIds) {
            return $this->cap($trending, $limit, $per);
        }

        // The latest watched weigh more: 1, 0.9, 0.8... over the last ten.
        $scores = [];
        foreach (\array_slice($videoIds, 0, 10) as $rank => $id) {
            foreach ($this->related->scoresFor([$id], 60) as $neighbour => $score) {
                if (!isset($seen[$neighbour])) {
                    $scores[$neighbour] = ($scores[$neighbour] ?? 0) + $score * max(0.1, 1 - $rank / 10);
                }
            }
        }
        arsort($scores);
        $near = $this->videos->findListedByIds(array_keys(\array_slice($scores, 0, $limit * 3, true)));

        // Four in five from the neighbours, one in five from the trends.
        $mixed = [];
        $trends = (int) ceil($limit / 5);
        $fromNear = $this->cap($near, $limit - min($trends, \count($trending)), $per);
        foreach ($fromNear as $i => $video) {
            $mixed[] = $video;
            if (0 === ($i + 1) % 4 && $trending) {
                $mixed[] = array_shift($trending);
            }
        }
        foreach ($trending as $video) {
            if (\count($mixed) >= $limit) {
                break;
            }
            if (!\in_array($video, $mixed, true)) {
                $mixed[] = $video;
            }
        }

        return $this->cap($mixed, $limit, $per);
    }

    public function similar(Video $video, int $limit = 12): array
    {
        $similar = $this->related->findFor($video, $limit * 2);
        if (\count($similar) < $limit) {
            // Not computed yet (a new film): its channel's, then the most seen.
            $channel = $video->getChannel();
            foreach (array_merge($channel ? $this->videos->findLatest($limit, 0, $channel) : [], $this->videos->findPopular($limit)) as $candidate) {
                if ($candidate !== $video && !\in_array($candidate, $similar, true)) {
                    $similar[] = $candidate;
                }
            }
        }

        return $this->cap(array_values(array_filter($similar, fn (Video $v) => $v !== $video && $v->getId() !== $video->getId())), $limit);
    }

    /**
     * @param list<Video> $videos
     *
     * @return list<Video> at most $per (per_channel by default) of one channel, then $limit in all
     */
    public function cap(array $videos, int $limit, ?int $per = null): array
    {
        $per ??= $this->perChannel;
        $kept = [];
        $perChannel = [];
        foreach ($videos as $video) {
            if (\in_array($video, $kept, true)) {
                continue;
            }
            $key = $video->getChannel()?->getId() ?? 0;
            if ($per > 0 && ($perChannel[$key] ?? 0) >= $per) {
                continue;
            }
            $perChannel[$key] = ($perChannel[$key] ?? 0) + 1;
            $kept[] = $video;
            if (\count($kept) >= $limit) {
                break;
            }
        }

        return $kept;
    }
}
