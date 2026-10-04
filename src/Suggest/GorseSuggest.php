<?php

namespace Base\Video\Suggest;

use Base\Entity\User;
use Base\Service\SettingBagInterface;
use Base\Video\Entity\Video;
use Base\Video\Repository\VideoRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gorse (gorse-in-one, Apache-2.0), spoken to over its REST API with
 * symfony/http-client - no client library:
 *
 *   POST /api/feedback                 read, watch, like (sent by Messenger)
 *   GET  /api/recommend/{user}?n=      for a member
 *   POST /api/session/recommend?n=     for a visitor, from what they watched
 *   GET  /api/item/{id}/neighbors?n=   "à suivre"
 *
 * The items are the videos' ids, the users the accounts' ids (Gorse adds
 * them on their first feedback). Any failure throws: Suggestions catches it
 * and answers with the local suggestions.
 */
class GorseSuggest implements SuggestInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly VideoRepository $videos,
        #[Autowire('%video.suggest.gorse_url%')] private readonly string $url = '',
        #[Autowire('%video.suggest.gorse_key%')] private readonly string $key = '',
        private readonly ?SettingBagInterface $settings = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->url);
    }

    public function feedback(string $type, User $user, Video $video): void
    {
        if ($user->getId() && $video->getId()) {
            $this->send($type, (string) $user->getId(), $video->getId());
        }
    }

    public function send(string $type, string $userId, int $videoId, ?\DateTimeInterface $at = null): void
    {
        $this->call('POST', '/api/feedback', ['json' => [[
            'FeedbackType' => $type,
            'UserId' => $userId,
            'ItemId' => (string) $videoId,
            'Timestamp' => ($at ?? new \DateTimeImmutable())->format(\DATE_ATOM),
        ]]]);
    }

    public function recommend(User $user, int $limit = 24): array
    {
        return $this->videos->findListedByIds(array_map('intval', (array) $this->call('GET', sprintf('/api/recommend/%s', rawurlencode((string) $user->getId())), ['query' => ['n' => $limit]])));
    }

    public function session(array $videoIds, int $limit = 24): array
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $feedback = array_map(fn ($id) => ['FeedbackType' => 'read', 'UserId' => '', 'ItemId' => (string) $id, 'Timestamp' => $now], \array_slice($videoIds, 0, 20));
        $scores = (array) $this->call('POST', '/api/session/recommend', ['query' => ['n' => $limit], 'json' => $feedback]);

        return $this->videos->findListedByIds(self::ids($scores));
    }

    public function similar(Video $video, int $limit = 12): array
    {
        $scores = (array) $this->call('GET', sprintf('/api/item/%d/neighbors', $video->getId()), ['query' => ['n' => $limit]]);

        return $this->videos->findListedByIds(self::ids($scores));
    }

    /** [{Id, Score}...] or ["12", "7"...] => [12, 7] */
    public static function ids(array $scores): array
    {
        return array_values(array_filter(array_map(fn ($row) => (int) (\is_array($row) ? ($row['Id'] ?? 0) : $row), $scores)));
    }

    private function call(string $method, string $path, array $options = []): mixed
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('Gorse is not configured (video.suggest.gorse_url).');
        }
        $key = trim((string) ($this->settings?->getScalar('api.gorse.key') ?? '')) ?: $this->key;
        $options += ['timeout' => 2.5];
        if ('' !== $key) {
            $options['headers']['X-API-Key'] = $key;
        }
        $response = $this->client->request($method, rtrim($this->url, '/').$path, $options);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('Gorse answered %d on %s.', $response->getStatusCode(), $path));
        }
        $content = $response->getContent();

        return '' === $content ? null : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
