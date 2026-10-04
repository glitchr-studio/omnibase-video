<?php

namespace Base\Video\Service;

use Base\Entity\User;
use Base\Video\Entity\Video;
use Base\Video\Message\TranscodeVideo;
use Base\Video\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The resumable uploads, with tusd. The studio hands the browser a signed
 * token (who, until when, and - for a replacement - which video); Uppy
 * sends it in the upload's metadata; tusd asks the site twice:
 *
 *   pre-create   may this upload start? - a valid token, a member allowed
 *                to send (verified, not banned), a file under the size
 *                limit, the quota and the daily limiter respected
 *   post-finish  the file is whole - the video is made (or the replaced one
 *                found), a draft, and its transcoding queued
 *
 * tusd's HTTP hooks (v2): a JSON request {Type, Event: {Upload: {ID, Size,
 * MetaData, Storage: {Path}}, HTTPRequest}}, answered with {} to go on or
 * {RejectUpload: true, HTTPResponse: {StatusCode, Body}} to refuse.
 */
class Uploads
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoRepository $videos,
        private readonly Channels $channels,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'limiter.video_upload')] private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%video.upload.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%video.upload.quota%')] private readonly int $quota = 0,
        #[Autowire('%video.upload.max_size%')] private readonly int $maxSize = 0,
        #[Autowire('%video.upload.verified_only%')] private readonly bool $verifiedOnly = true,
        #[Autowire('%video.upload.directory%')] private readonly string $directory = '',
        #[Autowire('%video.upload.token_ttl%')] private readonly int $ttl = 86400,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** A token for $user's next upload (or the replacement of $replace's file). */
    public function token(User $user, ?Video $replace = null, ?int $now = null): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['u' => $user->getId(), 'e' => ($now ?? time()) + $this->ttl, 'r' => $replace?->getId()])), '+/', '-_'), '=');

        return $payload.'.'.$this->sign($payload);
    }

    /** @return array{u: int, e: int, r: ?int}|null what a valid token says */
    public function verify(?string $token, ?int $now = null): ?array
    {
        if (!$token || 1 !== substr_count($token, '.')) {
            return null;
        }
        [$payload, $signature] = explode('.', $token);
        if (!hash_equals($this->sign($payload), $signature)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (!\is_array($data) || !isset($data['u'], $data['e']) || $data['e'] < ($now ?? time())) {
            return null;
        }

        return ['u' => (int) $data['u'], 'e' => (int) $data['e'], 'r' => isset($data['r']) ? (int) $data['r'] : null];
    }

    /** Bytes $user may still send (null: no quota). */
    public function remaining(User $user): ?int
    {
        return $this->quota > 0 ? max(0, $this->quota - $this->videos->sumUploads($user)) : null;
    }

    /** Why $user may not send a file of $size bytes now, or null. */
    public function refusal(?User $user, int $size, bool $consume = false): ?string
    {
        return match (true) {
            !$this->enabled => 'upload.refused.disabled',
            null === $user => 'upload.refused.token',
            $user->isBanned() || $user->isLocked() => 'upload.refused.account',
            $this->verifiedOnly && !$user->isVerified() => 'upload.refused.verified',
            $this->maxSize > 0 && $size > $this->maxSize => 'upload.refused.size',
            null !== ($left = $this->remaining($user)) && $size > $left => 'upload.refused.quota',
            $consume && !$this->limiter->create('user-'.$user->getId())->consume()->isAccepted() => 'upload.refused.limit',
            default => null,
        };
    }

    /**
     * tusd's question, answered.
     *
     * @return array<string, mixed> the hook's response
     */
    public function hook(array $hook): array
    {
        $type = (string) ($hook['Type'] ?? '');
        $upload = (array) ($hook['Event']['Upload'] ?? []);
        $meta = (array) ($upload['MetaData'] ?? []);
        $token = $this->verify($meta['token'] ?? null);
        $user = $token ? $this->entityManager->getRepository(User::class)->find($token['u']) : null;

        if ('pre-create' === $type) {
            $replace = $token['r'] ?? null;
            if ($replace && !$this->ownedVideo($replace, $user)) {
                return self::reject(403, 'upload.refused.replace');
            }
            if ($why = $this->refusal($user instanceof User ? $user : null, (int) ($upload['Size'] ?? 0), true)) {
                return self::reject($this->enabled ? 403 : 404, $why);
            }

            return [];
        }

        if ('post-finish' === $type) {
            if (!$user instanceof User) {
                return [];
            }
            $path = (string) ($upload['Storage']['Path'] ?? '');
            if (!$this->isUploadPath($path)) {
                return [];
            }
            $this->finish($user, (string) ($upload['ID'] ?? ''), $path, (int) ($upload['Size'] ?? 0), (string) ($meta['filename'] ?? $meta['name'] ?? ''), $token['r'] ?? null);
        }

        return [];
    }

    /** The video made of a finished upload (or the one whose file it replaces), its transcoding queued. */
    public function finish(User $user, string $uploadId, string $path, int $size, string $filename, ?int $replace = null): ?Video
    {
        // tusd may call twice (a retry): the first one made it.
        if ('' !== $uploadId && ($existing = $this->videos->findOneByUploadId($uploadId))) {
            return $existing;
        }

        $video = $replace ? $this->ownedVideo($replace, $user) : null;
        if (!$video) {
            $title = trim(preg_replace('/[_\s]+/u', ' ', pathinfo($filename, \PATHINFO_FILENAME)) ?? '') ?: 'Vidéo sans titre';
            $video = new Video($user, null, mb_substr($title, 0, 150));
            $video->setSlug(self::slug());
            $video->setChannel($this->channels->forMember($user));
            $video->setVisibility(Video::VISIBILITY_PRIVATE);
            $this->entityManager->persist($video);
        }
        $video->setUploadId($uploadId ?: null)->setUploadSize($size)->setOriginalName($filename ?: null)
            ->setProcessing(Video::PROCESSING_UPLOADED)->setFailure(null);
        $this->entityManager->flush();

        $this->bus->dispatch(new TranscodeVideo($video->getId(), $path));

        return $video;
    }

    /** A short random address part, already what omnibase's Slugify keeps (lowercase): /v/3f9a0c71be42. */
    public static function slug(): string
    {
        return bin2hex(random_bytes(6));
    }

    /** Inside the uploads directory, as tusd wrote it (never a path a forged hook names elsewhere). */
    public function isUploadPath(string $path): bool
    {
        $root = realpath($this->directory);
        $file = realpath($path);

        return false !== $root && false !== $file && str_starts_with($file, rtrim($root, '/').'/') && is_file($file);
    }

    private function ownedVideo(int $id, ?User $user): ?Video
    {
        $video = $this->videos->find($id);

        return $video instanceof Video && $video->isOwnedBy($user) ? $video : null;
    }

    private function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'video-upload|'.$payload, $this->secret, true)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private static function reject(int $status, string $why): array
    {
        return ['RejectUpload' => true, 'HTTPResponse' => ['StatusCode' => $status, 'Body' => $why, 'Header' => ['Content-Type' => 'text/plain']]];
    }
}
