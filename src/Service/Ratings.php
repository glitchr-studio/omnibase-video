<?php

namespace Base\Video\Service;

use Base\Entity\Thread\Like;
use Base\Entity\User;
use Base\Video\Entity\Video;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "J'aime" and "je n'aime pas", on omnibase's own Like (Base\Entity\Thread\Like,
 * on any thread): a thumbs-up is the core's icon, a thumbs-down another
 * icon on the same row. One rating a member and film; choosing the other
 * one turns it over, choosing the same one again takes it back. The thread's
 * row is locked while it is decided (a double click made two rows).
 */
class Ratings
{
    public const UP = 'fa-solid fa-thumbs-up';
    public const DOWN = 'fa-solid fa-thumbs-down';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Views $views,
    ) {
    }

    /** @return 'up'|'down'|null what $user says of $video */
    public function of(?User $user, Video $video): ?string
    {
        if (!$user?->getId()) {
            return null;
        }
        foreach ($video->getLikes() as $like) {
            if ($like->getUser()?->getId() === $user->getId()) {
                return self::DOWN === $like->getIcon() ? 'down' : 'up';
            }
        }

        return null;
    }

    /** @return array{up: int, down: int} */
    public function count(Video $video): array
    {
        $up = $down = 0;
        foreach ($video->getLikes() as $like) {
            self::DOWN === $like->getIcon() ? ++$down : ++$up;
        }

        return ['up' => $up, 'down' => $down];
    }

    /**
     * @param 'up'|'down'|'none' $rating
     *
     * @return 'up'|'down'|null the member's rating after it
     */
    public function rate(User $user, Video $video, string $rating): ?string
    {
        $this->entityManager->beginTransaction();
        try {
            if ($video->getId()) {
                $this->entityManager->lock($video, LockMode::PESSIMISTIC_WRITE);
            }
            $existing = null;
            foreach ($video->getLikes() as $like) {
                if ($like->getUser()?->getId() === $user->getId()) {
                    $existing = $like;
                    break;
                }
            }
            $icon = 'down' === $rating ? self::DOWN : self::UP;
            $result = null;
            if ('none' === $rating || ($existing && $existing->getIcon() === $icon)) {
                if ($existing) {
                    $video->removeLike($existing);
                    $user->getLikes()->removeElement($existing);
                    $this->entityManager->remove($existing);
                }
            } elseif ($existing) {
                $existing->setIcon($icon);
                $result = 'down' === $rating ? 'down' : 'up';
            } else {
                $like = new Like($user);
                $like->setIcon($icon);
                $like->setThread($video);
                $video->getLikes()->add($like);
                $this->entityManager->persist($like);
                $result = 'down' === $rating ? 'down' : 'up';
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
        if ('up' === $result) {
            $this->views->feedback('like', $user, $video);
        }

        return $result;
    }
}
