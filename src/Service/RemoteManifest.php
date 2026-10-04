<?php

namespace Base\Video\Service;

use Base\Video\Exception\RemoteException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Someone else's HLS, played without a copy. A master playlist that its
 * origin serves without CORS is read here, kept a while (video.remote.ttl)
 * and given to the browser from our address, its URIs made absolute: the
 * variant playlists and the segments are then fetched by the browser from
 * the origin itself, which allows it. Nothing else of the film passes
 * through the site.
 *
 * Only the hosts in video.remote.hosts are read (no open proxy).
 */
class RemoteManifest
{
    /** @param list<string> $hosts */
    public function __construct(
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'video.cache')] private readonly CacheInterface $cache,
        #[Autowire('%video.remote.hosts%')] private readonly array $hosts = [],
        #[Autowire('%video.remote.ttl%')] private readonly int $ttl = 3600,
    ) {
    }

    public function allows(string $url): bool
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        if ('' === $host || 'https' !== strtolower((string) parse_url($url, \PHP_URL_SCHEME))) {
            return false;
        }
        foreach ($this->hosts as $allowed) {
            $allowed = strtolower(ltrim($allowed, '.'));
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** The master playlist at $url, its URIs absolute. */
    public function master(string $url): string
    {
        if (!$this->allows($url)) {
            throw new RemoteException(sprintf('"%s" is not among the hosts video.remote.hosts allows.', parse_url($url, \PHP_URL_HOST)));
        }

        return $this->cache->get('video.master.'.hash('xxh128', $url), function (ItemInterface $item) use ($url) {
            $item->expiresAfter($this->ttl);

            return self::absolutize($this->fetch($url), $url);
        });
    }

    /** The film's length: the first variant's #EXTINF summed (null when unreadable). */
    public function duration(string $url): ?int
    {
        try {
            $master = $this->master($url);
            $variant = self::variants($master)[0] ?? null;
            $playlist = $variant ? $this->fetch($variant) : $master;

            return self::sum($playlist) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Every URI line and URI="..." attribute made absolute against $base. */
    public static function absolutize(string $playlist, string $base): string
    {
        if (!str_starts_with(ltrim($playlist), '#EXTM3U')) {
            throw new RemoteException('Not an HLS playlist.');
        }
        $lines = preg_split('/\r\n|\n|\r/', $playlist) ?: [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }
            if (str_starts_with($trimmed, '#')) {
                $lines[$i] = preg_replace_callback('/URI="([^"]+)"/', fn ($m) => 'URI="'.self::resolve($m[1], $base).'"', $line);
                continue;
            }
            $lines[$i] = self::resolve($trimmed, $base);
        }

        return implode("\n", $lines);
    }

    /** @return list<string> the variant playlists of a master, in its order */
    public static function variants(string $master): array
    {
        $variants = [];
        $next = false;
        foreach (preg_split('/\r\n|\n|\r/', $master) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                $next = true;
            } elseif ($next && '' !== $line && !str_starts_with($line, '#')) {
                $variants[] = $line;
                $next = false;
            }
        }

        return $variants;
    }

    /** Seconds, the #EXTINF of a media playlist summed. */
    public static function sum(string $playlist): int
    {
        preg_match_all('/#EXTINF:\s*([\d.]+)/', $playlist, $m);

        return (int) round(array_sum(array_map('floatval', $m[1])));
    }

    public static function resolve(string $uri, string $base): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri)) {
            return $uri;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($uri, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$uri;
        }
        if (str_starts_with($uri, '/')) {
            return $origin.$uri;
        }
        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$directory.$uri;
    }

    private function fetch(string $url): string
    {
        try {
            $response = $this->client->request('GET', $url, ['timeout' => 10, 'max_redirects' => 3, 'headers' => ['Accept' => 'application/vnd.apple.mpegurl, application/x-mpegURL, */*']]);
            if (200 !== $response->getStatusCode()) {
                throw new RemoteException(sprintf('%s answered %d.', parse_url($url, \PHP_URL_HOST), $response->getStatusCode()));
            }

            return $response->getContent();
        } catch (RemoteException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RemoteException(sprintf('%s could not be read: %s', parse_url($url, \PHP_URL_HOST), $e->getMessage()), 0, $e);
        }
    }
}
