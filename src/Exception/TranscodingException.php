<?php

namespace Base\Video\Exception;

/** ffmpeg or ffprobe missing, failing, or a film it cannot read. */
class TranscodingException extends \RuntimeException
{
    public static function missing(string $binary): self
    {
        return new self(sprintf('"%s" was not found: install ffmpeg on the worker (video.transcode.ffmpeg / ffprobe).', $binary));
    }
}
