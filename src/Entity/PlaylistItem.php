<?php

namespace Base\Video\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_playlist_item')]
#[ORM\UniqueConstraint(name: 'video_playlist_item_unique', columns: ['playlist_id', 'video_id'])]
class PlaylistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'playlist_id', nullable: false, onDelete: 'CASCADE')]
    protected Playlist $playlist;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', nullable: false, onDelete: 'CASCADE')]
    protected Video $video;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $addedAt;

    public function __construct(Playlist $playlist, Video $video, int $position = 0)
    {
        $this->playlist = $playlist;
        $this->video = $video;
        $this->position = $position;
        $this->addedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getPlaylist(): Playlist { return $this->playlist; }
    public function getVideo(): Video { return $this->video; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
    public function getAddedAt(): \DateTimeInterface { return $this->addedAt; }
}
