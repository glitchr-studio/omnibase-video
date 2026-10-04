<?php

namespace Base\Video\Entity;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread\Comment;
use Base\Video\Repository\VideoCommentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * omnibase's comment (Base\Entity\Thread\Comment: its states, its replies
 * on one level, its Akismet score) with what a film adds: the moment it was
 * written at - where the player stood - and whether its author pinned it.
 * The "1:23" typed in a text are made clickable by the |timecodes filter;
 * the moment is the comment's own.
 */
#[ORM\Entity(repositoryClass: VideoCommentRepository::class)]
#[ORM\Table(name: 'video_comment')]
#[DiscriminatorEntry(value: 'video_comment')]
class VideoComment extends Comment
{
    /** Seconds into the film; null: written before it played, or after. */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $moment = null;

    /** Pinned by the video's author: first under the film. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $pinned = false;

    public function getMoment(): ?int { return $this->moment; }

    public function setMoment(?int $moment): static
    {
        $this->moment = null !== $moment && $moment >= 0 ? $moment : null;

        return $this;
    }

    public function getMomentText(): string
    {
        return Video::formatTime($this->moment);
    }

    public function isPinned(): bool { return $this->pinned; }
    public function setPinned(bool $pinned): static { $this->pinned = $pinned; return $this; }

    public function getVideo(): ?Video
    {
        $thread = $this->getThread();

        return $thread instanceof Video ? $thread : null;
    }

    /**
     * The moments typed in a text - 1:23, 01:02:03 - in seconds, in the
     * order they come. Hours only before minutes; minutes and seconds under
     * sixty past the first number.
     *
     * @return list<int>
     */
    public static function timecodes(string $text): array
    {
        preg_match_all(self::TIMECODE, $text, $matches, \PREG_SET_ORDER);

        return array_map(fn (array $m) => self::seconds($m), $matches);
    }

    public const TIMECODE = '/(?<![\d:])(?:(\d{1,2}):)?([0-5]?\d):([0-5]\d)(?![\d:])/';

    /** @param array<int, string> $m a TIMECODE match */
    public static function seconds(array $m): int
    {
        return ((int) ($m[1] ?? 0)) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }
}
