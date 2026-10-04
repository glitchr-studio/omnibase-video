<?php

namespace Base\Video\Service;

use Base\Video\Exception\TranscodingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A film turned into what the player reads, with ffmpeg (symfony/process):
 *
 *   master.m3u8          the HLS master, one variant a rung
 *   <height>/index.m3u8  each rung: fMP4 segments (seg_000.m4s...) and its init
 *   poster.jpg           the still (frame-2.jpg: about a third in)
 *   frame-<n>.jpg        stills the author picks a poster from
 *   storyboard.jpg/.vtt  the time bar's previews: a sprite and its boxes
 *
 * The ladder is 1080, 720, 480, 360 by default (video.transcode.renditions),
 * never above the source: a 720p film gets 720, 480, 360. Every rung has a
 * key frame on each segment's start (-force_key_frames), so the player can
 * switch between them at any boundary.
 *
 * The *Command() methods only assemble arguments - no file is touched - so
 * they are read and tested as they are. Modelled on omnibase/social's
 * Renderer.
 */
class Transcoder
{
    /** Height => [video bit rate, its ceiling], kb/s. */
    public const BITRATES = [
        2160 => [14000, 16000],
        1440 => [8000, 9000],
        1080 => [5000, 5600],
        720 => [2800, 3100],
        480 => [1400, 1600],
        360 => [800, 900],
        240 => [400, 450],
    ];

    public const THUMB_WIDTH = 160;
    public const SPRITE_COLUMNS = 10;

    /** @param list<int> $renditions */
    public function __construct(
        #[Autowire('%video.transcode.ffmpeg%')] private readonly string $ffmpeg = 'ffmpeg',
        #[Autowire('%video.transcode.ffprobe%')] private readonly string $ffprobe = 'ffprobe',
        #[Autowire('%video.transcode.renditions%')] private readonly array $renditions = [1080, 720, 480, 360],
        #[Autowire('%video.transcode.segment%')] private readonly int $segment = 6,
        #[Autowire('%video.transcode.preset%')] private readonly string $preset = 'veryfast',
        #[Autowire('%video.transcode.timeout%')] private readonly int $timeout = 14400,
        #[Autowire('%video.transcode.thumbnails%')] private readonly int $thumbnails = 100,
        #[Autowire('%video.transcode.frames%')] private readonly int $frames = 4,
    ) {
    }

    /**
     * Everything for one film, written into $directory.
     *
     * @return array{duration: float, width: int, height: int, audio: bool, rungs: list<array{height: int, width: int, bitrate: int, maxrate: int, path: string}>, poster: string, frames: list<string>, storyboard: ?string}
     */
    public function transcode(string $source, string $directory): array
    {
        $probe = $this->probe($source);
        if ($probe['width'] <= 0 || $probe['height'] <= 0) {
            throw new TranscodingException('No picture in this file: is it a film?');
        }
        $rungs = $this->ladder($probe['width'], $probe['height']);
        $this->run($this->hlsCommand($source, $directory, $rungs, $probe['audio']), 'The transcoding');

        $frames = [];
        foreach ($this->frameTimes($probe['duration']) as $i => $second) {
            $file = sprintf('frame-%d.jpg', $i + 1);
            $this->run($this->stillCommand($source, $second, $directory.'/'.$file), 'A still', null, false);
            if (is_file($directory.'/'.$file)) {
                $frames[] = $file;
            }
        }
        $poster = $frames[min(1, \count($frames) - 1)] ?? null;
        if (null !== $poster) {
            copy($directory.'/'.$poster, $directory.'/poster.jpg');
        }

        $storyboard = null;
        $board = $this->storyboard($probe['duration'], $probe['width'], $probe['height']);
        if ($board) {
            try {
                $this->run($this->storyboardCommand($source, $directory.'/storyboard.jpg', $board), 'The storyboard');
                file_put_contents($directory.'/storyboard.vtt', self::storyboardVtt($probe['duration'], $board, 'storyboard.jpg'));
                $storyboard = 'storyboard.vtt';
            } catch (TranscodingException) {
                // A film without its previews still plays.
            }
        }

        return [
            'duration' => $probe['duration'],
            'width' => $probe['width'],
            'height' => $probe['height'],
            'audio' => $probe['audio'],
            'rungs' => array_map(fn (array $rung) => $rung + ['path' => ($rung['name'] ?? $rung['height']).'/index.m3u8'], $rungs),
            'poster' => is_file($directory.'/poster.jpg') ? 'poster.jpg' : '',
            'frames' => $frames,
            'storyboard' => $storyboard,
        ];
    }

    /**
     * The rungs for a source of that size: the configured heights not above
     * it (its own height when it is below them all), the width kept to the
     * source's shape and even.
     *
     * @return list<array{height: int, width: int, bitrate: int, maxrate: int}>
     */
    public function ladder(int $width, int $height): array
    {
        // A portrait film: the ladder applies to its short side.
        $short = min($width, $height);
        $heights = array_values(array_filter($this->renditions, fn (int $h) => $h <= $short));
        rsort($heights);
        if (!$heights) {
            $heights = [self::even($short)];
        }

        $rungs = [];
        foreach (array_unique($heights) as $h) {
            [$bitrate, $maxrate] = self::bitrate($h);
            $portrait = $height > $width;
            $rungs[] = [
                'height' => $portrait ? self::even($h * $height / $width) : $h,
                'width' => $portrait ? $h : self::even($h * $width / $height),
                'bitrate' => $bitrate,
                'maxrate' => $maxrate,
                'name' => $h,
            ];
        }

        return array_map(fn (array $rung) => ['height' => $rung['height'], 'width' => $rung['width'], 'bitrate' => $rung['bitrate'], 'maxrate' => $rung['maxrate']] + ($rung['name'] !== $rung['height'] ? ['name' => $rung['name']] : []), $rungs);
    }

    /**
     * ffmpeg's arguments for the whole ladder in one pass: the picture split
     * and scaled per rung, the sound once a rung, fMP4 HLS with a master.
     *
     * @param list<array{height: int, width: int, bitrate: int, maxrate: int, name?: int}> $rungs
     *
     * @return list<string>
     */
    public function hlsCommand(string $source, string $directory, array $rungs, bool $audio = true): array
    {
        $n = \count($rungs);
        $graph = [$n > 1 ? sprintf('[0:v]split=%d%s', $n, implode('', array_map(fn ($i) => "[s$i]", range(0, $n - 1)))) : '[0:v]null[s0]'];
        foreach ($rungs as $i => $rung) {
            $graph[] = sprintf('[s%d]scale=%d:%d:force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1[o%d]', $i, $rung['width'], $rung['height'], $i);
        }

        $arguments = [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error', '-i', $source, '-filter_complex', implode(';', $graph)];
        foreach ($rungs as $i => $rung) {
            array_push($arguments,
                '-map', "[o$i]",
                "-c:v:$i", 'libx264',
                "-b:v:$i", $rung['bitrate'].'k',
                "-maxrate:v:$i", $rung['maxrate'].'k',
                "-bufsize:v:$i", ($rung['bitrate'] * 3 / 2).'k',
            );
        }
        if ($audio) {
            foreach ($rungs as $i => $rung) {
                array_push($arguments, '-map', '0:a:0');
            }
            array_push($arguments, '-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-ar', '48000');
        }
        $map = implode(' ', array_map(fn ($i, $rung) => sprintf('v:%d%s,name:%d', $i, $audio ? ",a:$i" : '', $rung['name'] ?? $rung['height']), array_keys($rungs), $rungs));

        return array_merge($arguments, [
            '-preset', $this->preset,
            '-profile:v', 'high',
            '-pix_fmt', 'yuv420p',
            '-force_key_frames', sprintf('expr:gte(t,n_forced*%d)', $this->segment),
            '-sc_threshold', '0',
            '-f', 'hls',
            '-hls_time', (string) $this->segment,
            '-hls_playlist_type', 'vod',
            '-hls_segment_type', 'fmp4',
            '-hls_flags', 'independent_segments',
            '-hls_fmp4_init_filename', 'init.mp4',
            '-hls_segment_filename', $directory.'/%v/seg_%03d.m4s',
            '-master_pl_name', 'master.m3u8',
            '-var_stream_map', $map,
            $directory.'/%v/index.m3u8',
        ]);
    }

    /** @return list<string> one frame at $second, 1280 wide at most */
    public function stillCommand(string $source, float $second, string $output): array
    {
        return [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error', '-ss', self::number(max(0.0, $second)), '-i', $source,
            '-frames:v', '1', '-vf', "scale='min(1280,iw)':-2", '-q:v', '3', $output];
    }

    /**
     * The sprite's shape: one picture every `interval` seconds, at most
     * video.transcode.thumbnails of them, ten a row.
     *
     * @return array{interval: float, count: int, columns: int, rows: int, width: int, height: int}|null
     */
    public function storyboard(float $duration, int $width, int $height): ?array
    {
        if ($duration < 2 || $width <= 0 || $height <= 0) {
            return null;
        }
        $interval = max(1.0, ceil($duration / max(1, $this->thumbnails)));
        $count = (int) max(1, min($this->thumbnails, ceil($duration / $interval)));
        $columns = min(self::SPRITE_COLUMNS, $count);

        return [
            'interval' => $interval,
            'count' => $count,
            'columns' => $columns,
            'rows' => (int) ceil($count / $columns),
            'width' => self::THUMB_WIDTH,
            'height' => self::even(self::THUMB_WIDTH * $height / $width),
        ];
    }

    /**
     * @param array{interval: float, count: int, columns: int, rows: int, width: int, height: int} $board
     *
     * @return list<string>
     */
    public function storyboardCommand(string $source, string $output, array $board): array
    {
        return [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error', '-i', $source,
            '-vf', sprintf('fps=1/%s,scale=%d:%d,tile=%dx%d', self::number($board['interval']), $board['width'], $board['height'], $board['columns'], $board['rows']),
            '-frames:v', '1', '-q:v', '5', $output];
    }

    /**
     * The WebVTT the time bar reads its previews from: a cue a picture,
     * pointing at its box in the sprite (#xywh=x,y,w,h).
     *
     * @param array{interval: float, count: int, columns: int, rows: int, width: int, height: int} $board
     */
    public static function storyboardVtt(float $duration, array $board, string $sprite): string
    {
        $lines = ['WEBVTT', ''];
        for ($i = 0; $i < $board['count']; ++$i) {
            $start = $i * $board['interval'];
            if ($start >= $duration) {
                break;
            }
            $end = min($duration, ($i + 1) * $board['interval']);
            $x = ($i % $board['columns']) * $board['width'];
            $y = intdiv($i, $board['columns']) * $board['height'];
            $lines[] = self::vttTime($start).' --> '.self::vttTime($end);
            $lines[] = sprintf('%s#xywh=%d,%d,%d,%d', $sprite, $x, $y, $board['width'], $board['height']);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @return list<float> where the stills offered as a poster are taken */
    public function frameTimes(float $duration): array
    {
        if ($duration <= 0) {
            return [0.0];
        }
        $n = max(1, $this->frames);
        $times = [];
        for ($i = 1; $i <= $n; ++$i) {
            $times[] = round($duration * $i / ($n + 1), 2);
        }

        return $times;
    }

    /**
     * What ffprobe measures: length, picture size (turned when the film is),
     * sound, weight.
     *
     * @return array{duration: float, width: int, height: int, audio: bool, size: int}
     */
    public function probe(string $path): array
    {
        if (!is_file($path)) {
            throw new TranscodingException(sprintf('"%s" is not there.', $path));
        }
        $output = $this->run([$this->ffprobe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path], 'The measuring', $this->ffprobe);

        return self::measures((array) json_decode($output, true), $path);
    }

    /** @return array{duration: float, width: int, height: int, audio: bool, size: int} */
    public static function measures(array $json, string $path = ''): array
    {
        $width = $height = 0;
        $audio = false;
        $duration = (float) ($json['format']['duration'] ?? 0);
        foreach ($json['streams'] ?? [] as $stream) {
            if ('audio' === ($stream['codec_type'] ?? null)) {
                $audio = true;
            }
            if ('video' !== ($stream['codec_type'] ?? null) || $width || 'mjpeg' === ($stream['codec_name'] ?? null) && !empty($stream['disposition']['attached_pic'])) {
                continue;
            }
            $width = (int) ($stream['width'] ?? 0);
            $height = (int) ($stream['height'] ?? 0);
            $rotation = (int) ($stream['tags']['rotate'] ?? 0);
            foreach ($stream['side_data_list'] ?? [] as $side) {
                $rotation = (int) ($side['rotation'] ?? $rotation);
            }
            if (90 === abs($rotation) % 180) {
                [$width, $height] = [$height, $width];
            }
            $duration = $duration ?: (float) ($stream['duration'] ?? 0);
        }

        return [
            'duration' => round($duration, 3),
            'width' => $width,
            'height' => $height,
            'audio' => $audio,
            'size' => (int) ($json['format']['size'] ?? ('' !== $path && is_file($path) ? filesize($path) : 0)),
        ];
    }

    /** @return array{0: int, 1: int} */
    public static function bitrate(int $height): array
    {
        foreach (self::BITRATES as $h => $rates) {
            if ($height >= $h) {
                return $rates;
            }
        }

        return [300, 350];
    }

    /** The nearest even number (853.3 -> 854): x264 wants even sides. */
    public static function even(int|float $value): int
    {
        return max(2, 2 * (int) round($value / 2));
    }

    /** 00:01:02.500 */
    public static function vttTime(float $seconds): string
    {
        $ms = (int) round($seconds * 1000);

        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** @param list<string> $command */
    private function run(array $command, string $what, ?string $binary = null, bool $strict = true): string
    {
        $binary ??= $this->ffmpeg;
        $found = str_contains($binary, '/') ? is_executable($binary) : null !== (new ExecutableFinder())->find($binary);
        if (!$found) {
            throw TranscodingException::missing($binary);
        }
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();
        if (!$process->isSuccessful() && $strict) {
            throw new TranscodingException(sprintf('%s failed: %s', $what, trim(mb_strimwidth($process->getErrorOutput() ?: $process->getOutput(), 0, 1500, '…'))));
        }

        return $process->getOutput();
    }
}
