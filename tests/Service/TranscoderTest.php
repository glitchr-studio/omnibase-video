<?php

namespace Base\Video\Tests\Service;

use Base\Video\Service\Transcoder;
use PHPUnit\Framework\TestCase;

class TranscoderTest extends TestCase
{
    public function testTheLadderNeverGoesAboveTheSource(): void
    {
        $transcoder = new Transcoder();
        self::assertSame([1080, 720, 480, 360], array_column($transcoder->ladder(1920, 1080), 'height'));
        self::assertSame([720, 480, 360], array_column($transcoder->ladder(1280, 720), 'height'));
        self::assertSame([1080, 720, 480, 360], array_column($transcoder->ladder(3840, 2160), 'height'), 'a 4K source stops at the tallest rung configured');
        self::assertSame([854, 640], \array_slice(array_column($transcoder->ladder(1280, 720), 'width'), 1), 'widths keep the shape and stay even');
    }

    public function testASourceBelowEveryRungKeepsItsOwnHeight(): void
    {
        $rungs = (new Transcoder())->ladder(426, 240);
        self::assertCount(1, $rungs);
        self::assertSame(240, $rungs[0]['height']);
        self::assertSame(426, $rungs[0]['width']);
    }

    public function testAPortraitFilmIsLadderedOnItsShortSide(): void
    {
        $rungs = (new Transcoder())->ladder(1080, 1920);
        self::assertSame([1080, 720, 480, 360], array_column($rungs, 'width'));
        self::assertSame(1920, $rungs[0]['height']);
        self::assertSame(1080, $rungs[0]['name'], 'its directory is named by the rung');
    }

    public function testTheHlsCommandMakesOneVariantARungInFmp4(): void
    {
        $transcoder = new Transcoder(segment: 6);
        $command = $transcoder->hlsCommand('/in/film.mov', '/out', $transcoder->ladder(1280, 720), true);
        $line = implode(' ', $command);

        self::assertSame('ffmpeg', $command[0]);
        self::assertStringContainsString('[0:v]split=3[s0][s1][s2]', $line);
        self::assertStringContainsString('[s1]scale=854:480:force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1[o1]', $line);
        self::assertStringContainsString('-hls_segment_type fmp4', $line);
        self::assertStringContainsString('-hls_playlist_type vod', $line);
        self::assertStringContainsString('-force_key_frames expr:gte(t,n_forced*6)', $line);
        self::assertStringContainsString('-master_pl_name master.m3u8', $line);
        self::assertStringContainsString('-var_stream_map v:0,a:0,name:720 v:1,a:1,name:480 v:2,a:2,name:360', $line);
        self::assertSame('/out/%v/index.m3u8', end($command));
        self::assertSame(3, \count(array_keys($command, '0:a:0', true)), 'the sound once a rung');
    }

    public function testASilentFilmMapsNoSound(): void
    {
        $transcoder = new Transcoder();
        $line = implode(' ', $transcoder->hlsCommand('/in/a.mp4', '/out', $transcoder->ladder(640, 360), false));
        self::assertStringNotContainsString('0:a:0', $line);
        self::assertStringContainsString('[0:v]null[s0]', $line);
        self::assertStringContainsString('-var_stream_map v:0,name:360', $line);
    }

    public function testTheStoryboardHoldsAtMostTheConfiguredPictures(): void
    {
        $transcoder = new Transcoder(thumbnails: 100);
        $short = $transcoder->storyboard(48.0, 1280, 720);
        self::assertSame(['interval' => 1.0, 'count' => 48, 'columns' => 10, 'rows' => 5, 'width' => 160, 'height' => 90], $short);
        $long = $transcoder->storyboard(3600.0, 1920, 1080);
        self::assertSame(36.0, $long['interval']);
        self::assertSame(100, $long['count']);
        self::assertNull($transcoder->storyboard(1.0, 1280, 720), 'too short for previews');

        $command = implode(' ', $transcoder->storyboardCommand('/in/a.mp4', '/out/storyboard.jpg', $short));
        self::assertStringContainsString('fps=1/1,scale=160:90,tile=10x5', $command);
    }

    public function testTheVttPointsAtEachBoxOfTheSprite(): void
    {
        $board = ['interval' => 2.0, 'count' => 12, 'columns' => 10, 'rows' => 2, 'width' => 160, 'height' => 90];
        $vtt = Transcoder::storyboardVtt(23.5, $board, 'storyboard.jpg');
        $lines = explode("\n", $vtt);

        self::assertSame('WEBVTT', $lines[0]);
        self::assertSame('00:00:00.000 --> 00:00:02.000', $lines[2]);
        self::assertSame('storyboard.jpg#xywh=0,0,160,90', $lines[3]);
        self::assertStringContainsString("00:00:20.000 --> 00:00:22.000\nstoryboard.jpg#xywh=0,90,160,90", $vtt, 'the eleventh starts the second row');
        self::assertStringContainsString('00:00:22.000 --> 00:00:23.500', $vtt, 'the last cue ends with the film');
        self::assertSame(12, substr_count($vtt, '#xywh='));
    }

    public function testFfprobesJsonIsRead(): void
    {
        $measures = Transcoder::measures(['format' => ['duration' => '120.034', 'size' => '272762009'], 'streams' => [
            ['codec_type' => 'audio'],
            ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'side_data_list' => [['rotation' => -90]]],
        ]]);
        self::assertSame(['duration' => 120.034, 'width' => 1080, 'height' => 1920, 'audio' => true, 'size' => 272762009], $measures, 'a phone\'s portrait film is stored lying down');
        self::assertFalse(Transcoder::measures(['streams' => [['codec_type' => 'video', 'width' => 640, 'height' => 360]]])['audio']);
    }

    public function testFrameTimesAndBitrates(): void
    {
        self::assertSame([24.0, 48.0, 72.0, 96.0], (new Transcoder(frames: 4))->frameTimes(120.0));
        self::assertSame([5000, 5600], Transcoder::bitrate(1080));
        self::assertSame([800, 900], Transcoder::bitrate(400));
        self::assertSame('01:02:03.500', Transcoder::vttTime(3723.5));
    }
}
