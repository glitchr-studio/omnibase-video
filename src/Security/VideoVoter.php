<?php

namespace Base\Video\Security;

use Base\Entity\User;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Playlist;
use Base\Video\Entity\Video;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * VIDEO_VIEW    a film reachable (listed or unlisted), or its owners', or the moderators'
 * VIDEO_EDIT    its owners (and its channel's), or an administrator
 * VIDEO_MODERATE  the moderators (ROLE_EDITOR and up): take down, hide a comment
 * Also on a Channel (VIDEO_EDIT: its owners) and a Playlist (VIDEO_VIEW, VIDEO_EDIT).
 */
final class VideoVoter extends Voter
{
    public const VIEW = 'VIDEO_VIEW';
    public const EDIT = 'VIDEO_EDIT';
    public const MODERATE = 'VIDEO_MODERATE';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::MODERATE], true)
            && ($subject instanceof Video || $subject instanceof Channel || $subject instanceof Playlist || null === $subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $user = $user instanceof User ? $user : null;
        $moderator = null !== $user && $this->decisions->decide($token, ['ROLE_EDITOR']);
        $admin = null !== $user && $this->decisions->decide($token, ['ROLE_ADMIN']);

        if (self::MODERATE === $attribute) {
            return $moderator;
        }

        return match (true) {
            $subject instanceof Video => self::VIEW === $attribute
                ? $subject->isReachable() || $subject->isOwnedBy($user) || $moderator
                : $subject->isOwnedBy($user) || $admin,
            $subject instanceof Channel => self::VIEW === $attribute || $subject->isOwnedBy($user) || $admin,
            $subject instanceof Playlist => self::VIEW === $attribute ? $subject->isReachableBy($user) || $admin : $subject->isOwnedBy($user),
            default => false,
        };
    }
}
