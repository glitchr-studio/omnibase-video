<?php

namespace Base\Video\Entity;

use Base\Video\Repository\RelatedRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "À suivre": a video's neighbours and how close they are, computed at
 * night by Suggest\RelatedCalculator (video:related). Read by LocalSuggest.
 */
#[ORM\Entity(repositoryClass: RelatedRepository::class)]
#[ORM\Table(name: 'video_related')]
#[ORM\UniqueConstraint(name: 'video_related_unique', columns: ['video_id', 'related_id'])]
#[ORM\Index(columns: ['video_id', 'score'], name: 'video_related_score_idx')]
class Related
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', nullable: false, onDelete: 'CASCADE')]
    protected Video $video;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'related_id', nullable: false, onDelete: 'CASCADE')]
    protected Video $related;

    #[ORM\Column(type: 'float')]
    protected float $score;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $computedAt;

    public function __construct(Video $video, Video $related, float $score)
    {
        $this->video = $video;
        $this->related = $related;
        $this->score = $score;
        $this->computedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): Video { return $this->video; }
    public function getRelated(): Video { return $this->related; }
    public function getScore(): float { return $this->score; }
    public function getComputedAt(): \DateTimeInterface { return $this->computedAt; }
}
