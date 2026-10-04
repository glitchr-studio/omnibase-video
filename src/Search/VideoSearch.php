<?php

namespace Base\Video\Search;

use Base\Video\Entity\Video;
use Base\Video\Repository\VideoRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Typesense\Bundle\ORM\Query\Query;
use Typesense\Bundle\ORM\TypesenseFinderInterface;

/**
 * The platform's search: Typesense (glitchr/typesense-bundle, the "video"
 * mapping of the site's config/packages/typesense.yaml - docs/search.md)
 * with typo tolerance and prefixes, filtered by length, date, category and
 * channel, the listed films only. With no such mapping, or the server down,
 * the database answers (every word in the title, the text, the channel).
 */
class VideoSearch
{
    public const PER_PAGE = 24;

    public function __construct(
        private readonly VideoRepository $videos,
        // Nullable: without the "video" mapping, no finder - and the database answers.
        #[Autowire(service: 'typesense.finder.video')] private readonly ?TypesenseFinderInterface $finder = null,
    ) {
    }

    /**
     * @param array{category?: ?string, channel?: ?string, duration?: ?string, date?: ?string, sort?: ?string} $filters
     *
     * @return array{videos: list<Video>, found: int, engine: string}
     */
    public function search(string $term, array $filters = [], int $page = 1): array
    {
        $term = trim($term);
        $page = max(1, $page);
        $finder = $this->finder;
        if ($finder) {
            try {
                $query = (new Query('title,tags,channel,text', '' === $term ? '*' : $term))
                    ->prefix(true)
                    ->numTypos(2)
                    ->page($page)
                    ->perPage(self::PER_PAGE)
                    ->filterBy('listed:=true')
                    ->sortBy(match ($filters['sort'] ?? null) {
                        'date' => 'published:desc',
                        'views' => 'views:desc',
                        default => '' === $term ? 'published:desc' : '_text_match:desc,views:desc',
                    });
                foreach (self::typesenseFilters($filters) as $filter) {
                    $query->addFilterBy($filter);
                }
                $response = $finder->query($query);
                if (200 === $response->getStatus()) {
                    $videos = array_values(array_filter((array) $response->getResults(), fn ($v) => $v instanceof Video && $v->isListed()));

                    return ['videos' => $videos, 'found' => (int) ($response->getFound() ?? \count($videos)), 'engine' => 'typesense'];
                }
            } catch (\Throwable) {
                // Typesense down: the database answers.
            }
        }

        $videos = $this->videos->searchListed($term, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return ['videos' => $videos, 'found' => \count($videos) + ($page - 1) * self::PER_PAGE, 'engine' => 'database'];
    }

    /** @return list<string> Typesense's filter_by clauses for the filters */
    public static function typesenseFilters(array $filters): array
    {
        $clauses = [];
        if (!empty($filters['category'])) {
            $clauses[] = 'category:='.self::quote((string) $filters['category']);
        }
        if (!empty($filters['channel'])) {
            $clauses[] = 'channelSlug:='.self::quote((string) $filters['channel']);
        }
        [$min, $max] = VideoRepository::durationBounds($filters['duration'] ?? null);
        if (null !== $min) {
            $clauses[] = 'duration:>='.$min;
        }
        if (null !== $max) {
            $clauses[] = 'duration:<'.$max;
        }
        if ($since = VideoRepository::dateSince($filters['date'] ?? null)) {
            $clauses[] = 'published:>='.$since->getTimestamp();
        }

        return $clauses;
    }

    private static function quote(string $value): string
    {
        return '`'.str_replace('`', '', $value).'`';
    }
}
