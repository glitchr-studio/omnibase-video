<?php

namespace Base\Video\Suggest;

use Base\Entity\User;
use Base\Video\Entity\Video;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The suggestions the pages ask for (SuggestInterface is this): Gorse when
 * video.suggest.provider is "gorse" and it answers enough, the local ones
 * otherwise - and to fill a short answer. A visitor's own films never come
 * back to them.
 */
class Suggestions implements SuggestInterface
{
    public function __construct(
        private readonly LocalSuggest $local,
        private readonly GorseSuggest $gorse,
        #[Autowire('%video.suggest.provider%')] private readonly string $provider = 'local',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function usesGorse(): bool
    {
        return 'gorse' === $this->provider && $this->gorse->isEnabled();
    }

    public function feedback(string $type, User $user, Video $video): void
    {
        if ($this->usesGorse()) {
            $this->attempt(fn () => $this->gorse->feedback($type, $user, $video) ?? []);
        }
    }

    public function recommend(User $user, int $limit = 24): array
    {
        return $this->merge($this->usesGorse() ? $this->attempt(fn () => $this->gorse->recommend($user, $limit)) : [], fn () => $this->local->recommend($user, $limit), $limit);
    }

    public function session(array $videoIds, int $limit = 24): array
    {
        return $this->merge($this->usesGorse() && $videoIds ? $this->attempt(fn () => $this->gorse->session($videoIds, $limit)) : [], fn () => $this->local->session($videoIds, $limit), $limit, $videoIds);
    }

    public function similar(Video $video, int $limit = 12): array
    {
        $found = $this->usesGorse() ? $this->attempt(fn () => $this->gorse->similar($video, $limit)) : [];

        return $this->merge(array_values(array_filter($found, fn (Video $v) => $v->getId() !== $video->getId())), fn () => $this->local->similar($video, $limit), $limit, [$video->getId()]);
    }

    /** @return list<Video> */
    private function attempt(callable $call): array
    {
        try {
            return (array) $call();
        } catch (\Throwable $e) {
            $this->logger?->info('Gorse did not answer, the local suggestions do: {message}', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param list<Video> $first
     * @param list<int>   $exclude
     *
     * @return list<Video>
     */
    private function merge(array $first, callable $fallback, int $limit, array $exclude = []): array
    {
        $exclude = array_flip($exclude);
        $videos = [];
        foreach (\count($first) >= $limit ? $first : array_merge($first, $fallback()) as $video) {
            if ($video instanceof Video && !isset($exclude[$video->getId()]) && !\in_array($video, $videos, true)) {
                $videos[] = $video;
            }
        }

        return \array_slice($videos, 0, $limit);
    }
}
