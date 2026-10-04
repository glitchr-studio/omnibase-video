<?php

namespace Base\Video\Service;

use Base\Entity\User;
use Base\Entity\User\Complaint;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reports and take-downs. A report is omnibase's Complaint (source
 * "video"), handled on omnibase/admin's screen for them: what is reported
 * (a video, a comment, a channel) is its location, its author the target.
 * A take-down hides the film with a reason its authors are told (the DSA's
 * "statement of reasons"); restoring it puts it back private.
 */
class Moderation
{
    public const REASONS = ['illegal', 'violence', 'hate', 'sexual', 'harassment', 'spam', 'copyright', 'privacy', 'other'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly Notifier $notifier,
    ) {
    }

    public function report(Video|VideoComment|Channel $subject, ?User $reporter, string $reason, string $text = ''): Complaint
    {
        $reason = \in_array($reason, self::REASONS, true) ? $reason : 'other';
        [$kind, $id, $name, $target, $url] = match (true) {
            $subject instanceof Video => ['video', $subject->getId(), (string) $subject->getTitle(), $subject->getOwner(), $this->urls->generate('video_watch', ['slug' => $subject->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)],
            $subject instanceof VideoComment => ['comment', $subject->getId(), mb_strimwidth((string) $subject->getContent(), 0, 120, '…'), $subject->getAuthor(), $subject->getVideo() ? $this->urls->generate('video_watch', ['slug' => $subject->getVideo()->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL).'#comment-'.$subject->getId() : null],
            default => ['channel', $subject->getId(), (string) $subject->getName(), $subject->getOwner(), $this->urls->generate('video_channel', ['slug' => $subject->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)],
        };

        $complaint = new Complaint($reporter instanceof User ? $reporter : null, trim($text) ?: $reason, 'video');
        $complaint->setLocation($kind.':'.$id);
        $complaint->setLocationName(mb_substr($name, 0, 128));
        if ($target instanceof User) {
            $complaint->setTarget($target);
            $complaint->setTargetName((string) $target);
        } elseif ($subject instanceof VideoComment) {
            $complaint->setTargetName($subject->getDisplayName());
        }
        $complaint->setContext(['kind' => $kind, 'id' => $id, 'reason' => $reason, 'url' => $url]);
        $this->entityManager->persist($complaint);
        $this->entityManager->flush();

        return $complaint;
    }

    public function takeDown(Video $video, string $reason): void
    {
        $video->takeDown($reason);
        $this->entityManager->flush();
        $this->notifier->takenDown($video);
    }

    public function restore(Video $video): void
    {
        $video->restore();
        $this->entityManager->flush();
    }

    /** The video or comment a complaint of ours is about. */
    public function subjectOf(Complaint $complaint): Video|VideoComment|Channel|null
    {
        if ('video' !== $complaint->getSource() || !preg_match('/^(video|comment|channel):(\d+)$/', (string) $complaint->getLocation(), $m)) {
            return null;
        }

        return $this->entityManager->find(match ($m[1]) {
            'video' => Video::class,
            'comment' => VideoComment::class,
            default => Channel::class,
        }, (int) $m[2]);
    }
}
