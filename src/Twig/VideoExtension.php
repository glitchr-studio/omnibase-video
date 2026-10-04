<?php

namespace Base\Video\Twig;

use Base\Entity\User;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Base\Video\Service\Channels;
use Base\Video\Service\Ratings;
use Base\Video\Service\Uploads;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The pages' words for films:
 *
 *   seconds|video_time          1:02:03
 *   text|timecodes(video)       the text escaped, its "1:23" made links that seek the player
 *   n|video_count               1,2 k / 3,4 M (the locale's comma)
 *   date|video_ago              "il y a 3 jours"
 *   text|video_excerpt(160)     the text without its tags, cut at a word
 *   video_rating(video)         {up, down, mine}
 *   video_subscribed(channel)   whether the member follows it
 *   video_upload_token(video?)  the signed token the studio's uploader sends
 *   video_categories()          the categories configured
 */
class VideoExtension extends AbstractExtension
{
    /** @param list<string> $categories */
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly Ratings $ratings,
        private readonly Channels $channels,
        private readonly Uploads $uploads,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
        #[Autowire('%video.categories%')] private readonly array $categories = [],
        #[Autowire('%video.licenses%')] private readonly array $licenses = [],
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('video_time', [Video::class, 'formatTime']),
            new TwigFilter('timecodes', [$this, 'timecodes'], ['is_safe' => ['html']]),
            new TwigFilter('video_count', [$this, 'count']),
            new TwigFilter('video_ago', [$this, 'ago']),
            new TwigFilter('video_excerpt', [self::class, 'excerpt']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('video_rating', [$this, 'rating']),
            new TwigFunction('video_subscribed', [$this, 'subscribed']),
            new TwigFunction('video_upload_token', [$this, 'uploadToken']),
            new TwigFunction('video_categories', fn () => $this->categories),
            new TwigFunction('video_licenses', fn () => $this->licenses),
        ];
    }

    /**
     * The text escaped, line breaks kept, addresses linked, and every
     * moment (1:23, 1:02:03) a link to the film at that second - the player
     * seeks there instead of loading the page again.
     */
    public function timecodes(?string $text, ?Video $video = null): string
    {
        $html = htmlspecialchars((string) $text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace_callback('#\bhttps?://[^\s<>"\']+#i', fn ($m) => sprintf('<a href="%1$s" rel="nofollow ugc noopener" target="_blank">%1$s</a>', $m[0]), $html) ?? $html;
        $base = $video?->getSlug() ? $this->urls->generate('video_watch', ['slug' => $video->getSlug()]) : '';
        $html = preg_replace_callback(VideoComment::TIMECODE, function (array $m) use ($base) {
            $seconds = VideoComment::seconds($m);

            return sprintf('<a class="video-timecode" href="%s?t=%d" data-seek="%d">%s</a>', $base, $seconds, $seconds, $m[0]);
        }, $html) ?? $html;

        return nl2br($html);
    }

    /** The text without its tags, on one line, cut at a word under $length characters. */
    public static function excerpt(?string $text, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false !== $space && $space > $length * .6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:').'…';
    }

    public function count(int|float|null $n, ?string $locale = null): string
    {
        $n = (float) $n;
        $comma = \in_array(substr($locale ?? \Locale::getDefault(), 0, 2), ['fr', 'de', 'it', 'es'], true);
        $format = function (float $value, string $suffix) use ($comma): string {
            $text = $value < 10 ? rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') : number_format($value, 0, '.', '');

            return ($comma ? str_replace('.', ',', $text) : $text).$suffix;
        };

        return match (true) {
            $n >= 1e9 => $format($n / 1e9, ' Md'),
            $n >= 1e6 => $format($n / 1e6, ' M'),
            $n >= 1e4 => $format($n / 1e3, ' k'),
            default => number_format($n, 0, $comma ? ',' : '.', $comma ? "\u{202F}" : ','),
        };
    }

    public function ago(?\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        if (!$date) {
            return '';
        }
        $seconds = max(0, ($now ?? new \DateTime())->getTimestamp() - $date->getTimestamp());
        [$unit, $n] = match (true) {
            $seconds < 60 => ['now', 0],
            $seconds < 3600 => ['minutes', intdiv($seconds, 60)],
            $seconds < 86400 => ['hours', intdiv($seconds, 3600)],
            $seconds < 86400 * 7 => ['days', intdiv($seconds, 86400)],
            $seconds < 86400 * 30 => ['weeks', intdiv($seconds, 86400 * 7)],
            $seconds < 86400 * 365 => ['months', intdiv($seconds, 86400 * 30)],
            default => ['years', intdiv($seconds, 86400 * 365)],
        };

        return $this->translator->trans('ago.'.$unit, ['count' => $n], 'video');
    }

    /** @return array{up: int, down: int, mine: ?string} */
    public function rating(Video $video): array
    {
        $user = $this->security->getUser();

        return $this->ratings->count($video) + ['mine' => $user instanceof User ? $this->ratings->of($user, $video) : null];
    }

    public function subscribed(Channel $channel): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->channels->isSubscribed($user, $channel);
    }

    public function uploadToken(?Video $replace = null): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->uploads->token($user, $replace) : null;
    }
}
