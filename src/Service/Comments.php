<?php

namespace Base\Video\Service;

use Base\Entity\User;
use Base\Form\Model\CommentModel;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Base\Video\Repository\VideoCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * A comment left under a film: omnibase's comment (its form, its guard,
 * Akismet's score) made a VideoComment with the moment of the film it was
 * written at, a reply attached to its comment (one level), the video's
 * authors told. A video whose comments are closed takes none.
 */
class Comments
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoCommentRepository $comments,
        private readonly Notifier $notifier,
        #[Autowire('%video.comments.auto_approve%')] private readonly bool $autoApprove = true,
    ) {
    }

    public function create(Video $video, CommentModel $model, ?User $user, Request $request, ?int $moment = null, ?int $parentId = null): VideoComment
    {
        /** @var VideoComment $comment */
        $comment = $model->toComment($video, $this->autoApprove, VideoComment::class);
        $comment->setMoment($moment);
        $comment->setIp($request->getClientIp());
        $comment->setUserAgent($request->headers->get('User-Agent'));
        if ($parentId && ($parent = $this->comments->find($parentId)) && $parent->getThread()?->getId() === $video->getId()) {
            $comment->setParent($parent);
        }
        // The film's author answering under it: no moderation for them.
        if ($user && $video->isOwnedBy($user) && $comment->isPending()) {
            $comment->approve();
        }
        $this->entityManager->persist($comment);
        $this->entityManager->flush();
        $this->notifier->commented($comment);

        return $comment;
    }

    /** Pinned first under the film (one at a time), or unpinned. */
    public function pin(VideoComment $comment, bool $pinned = true): void
    {
        if ($pinned && ($video = $comment->getVideo())) {
            $this->comments->unpinAll($video);
        }
        $comment->setPinned($pinned);
        $this->entityManager->flush();
    }

    /** Hidden by the film's author (omnibase's trash state), or shown again. */
    public function hide(VideoComment $comment, bool $hidden = true): void
    {
        $hidden ? $comment->trash() : $comment->approve();
        if ($hidden) {
            $comment->setPinned(false);
        }
        $this->entityManager->flush();
    }
}
