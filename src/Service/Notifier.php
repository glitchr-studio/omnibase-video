<?php

namespace Base\Video\Service;

use Base\Entity\User;
use Base\Entity\User\Notification;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a video's authors are told, in the bell of omnibase's notification
 * centre ('notify', in-app): their film is ready, or failed; somebody
 * commented it; the moderators took it down, and why. A notification that
 * cannot be sent (no notifier in a test, a mailer down) never stops what
 * caused it.
 */
class Notifier
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function transcoded(Video $video): void
    {
        $this->tell($video, 'notify.ready', $this->urls->generate('video_studio_edit', ['id' => $video->getId()]));
    }

    public function failed(Video $video): void
    {
        $this->tell($video, 'notify.failed', $this->urls->generate('video_studio_edit', ['id' => $video->getId()]), (string) $video->getFailure());
    }

    public function commented(VideoComment $comment): void
    {
        $video = $comment->getVideo();
        if (!$video) {
            return;
        }
        $author = $comment->getAuthor();
        $this->tell($video, $comment->isPending() ? 'notify.comment_pending' : 'notify.comment', $this->urls->generate('video_studio_comments', ['id' => $video->getId()]), mb_strimwidth((string) $comment->getContent(), 0, 240, '…'), ['name' => $comment->getDisplayName()], $author);
    }

    public function takenDown(Video $video): void
    {
        $this->tell($video, 'notify.taken_down', $this->urls->generate('video_studio_edit', ['id' => $video->getId()]), (string) $video->getTakedownReason());
    }

    /** @param array<string, string> $parameters */
    private function tell(Video $video, string $key, string $url, string $content = '', array $parameters = [], ?User $except = null): void
    {
        $title = $this->translator->trans($key, $parameters + ['title' => (string) $video->getTitle()], 'video');
        foreach ($video->getOwners() as $owner) {
            if (!$owner instanceof User || ($except && $owner->getId() === $except->getId())) {
                continue;
            }
            try {
                $notification = new Notification($title);
                $notification->setUser($owner);
                $notification->setTitle($title);
                $notification->setSubject($title);
                $notification->setContent('' !== $content ? $content : $title);
                $notification->setUrl($url);
                $notification->send('notify');
            } catch (\Throwable $e) {
                $this->logger?->warning('Video notification "{key}" not sent: {message}', ['key' => $key, 'message' => $e->getMessage()]);
            }
        }
    }
}
