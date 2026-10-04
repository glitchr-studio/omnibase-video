<?php

namespace Base\Video\Entity;

use Base\Video\Repository\SourceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a video plays from. One MASTER a video (the address the player is
 * given) and, for our own films, one RENDITION per rung of the ladder:
 *
 *   hls         our transcoding: a master playlist under the storage
 *   mp4         a progressive file (an old film, a fallback)
 *   remote-hls  someone else's HLS, read without a copy: the manifest
 *               route serves its master, the browser fetches the rest there
 *   embed       a platform's own player (YouTube, Vimeo, PeerTube...): its
 *               page address, framed through omnibase's embed_url()
 */
#[ORM\Entity(repositoryClass: SourceRepository::class)]
#[ORM\Table(name: 'video_source')]
class Source
{
    public const HLS = 'hls';
    public const MP4 = 'mp4';
    public const REMOTE_HLS = 'remote-hls';
    public const EMBED = 'embed';
    public const KINDS = [self::HLS, self::MP4, self::REMOTE_HLS, self::EMBED];

    public const MASTER = 'master';
    public const RENDITION = 'rendition';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Video::class, inversedBy: 'sources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Video $video = null;

    #[ORM\Column(length: 16)]
    protected string $kind = self::HLS;

    #[ORM\Column(length: 16, options: ['default' => self::MASTER])]
    protected string $role = self::MASTER;

    /** A path under the video's directory (hls, mp4), or an absolute address (remote-hls, embed). */
    #[ORM\Column(type: 'text')]
    protected string $url = '';

    /**
     * An outside source's reference - the platform (youtube, vimeo,
     * peertube...) and the film's id there - and nothing of its logic:
     * playing it is the platform's engine's business (glitchr/omnishow's,
     * registered in the player), or embed_url()'s frame until then.
     */
    #[ORM\Column(length: 32, nullable: true)]
    protected ?string $platform = null;

    #[ORM\Column(length: 190, nullable: true)]
    protected ?string $externalId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $width = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $height = null;

    /** Bits per second. */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $bitrate = null;

    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $codecs = null;

    public function __construct(string $kind = self::HLS, string $url = '', string $role = self::MASTER)
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown source kind "%s".', $kind));
        }
        $this->kind = $kind;
        $this->url = $url;
        $this->role = $role;
    }

    public function __toString(): string
    {
        return $this->kind.($this->height ? ' '.$this->height.'p' : '').' '.$this->url;
    }

    public function getId(): ?int { return $this->id; }

    public function getVideo(): ?Video { return $this->video; }
    public function setVideo(?Video $video): self { $this->video = $video; return $this; }

    public function getKind(): string { return $this->kind; }
    public function getRole(): string { return $this->role; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }

    public function isAbsolute(): bool { return (bool) preg_match('#^https?://#i', $this->url); }

    public function getPlatform(): ?string { return $this->platform; }
    public function setPlatform(?string $platform): self { $this->platform = $platform; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $externalId): self { $this->externalId = $externalId ?: null; return $this; }

    /** The reference of an outside film: [platform, id], from what embed_url() frames ("https://player.vimeo.com/video/76979871"). */
    public function setReference(?string $platform, ?string $frame): self
    {
        $this->platform = $platform ?: null;
        $path = trim((string) parse_url((string) $frame, \PHP_URL_PATH), '/');
        $this->externalId = '' !== $path ? mb_substr(basename($path), 0, 190) : null;

        return $this;
    }

    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function setSize(?int $width, ?int $height): self { $this->width = $width; $this->height = $height; return $this; }

    public function getBitrate(): ?int { return $this->bitrate; }
    public function setBitrate(?int $bitrate): self { $this->bitrate = $bitrate; return $this; }

    public function getCodecs(): ?string { return $this->codecs; }
    public function setCodecs(?string $codecs): self { $this->codecs = $codecs; return $this; }

    /** "1080p", for the quality menu. */
    public function getLabel(): string
    {
        return $this->height ? $this->height.'p' : $this->kind;
    }
}
