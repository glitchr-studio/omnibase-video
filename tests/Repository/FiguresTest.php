<?php

namespace Base\Video\Tests\Repository;

use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Base\Video\Search\VideoSearch;
use PHPUnit\Framework\TestCase;

class FiguresTest extends TestCase
{
    public function testTheStudiosFiguresFromRawSittings(): void
    {
        $today = new \DateTime('2026-10-04 15:00');
        $rows = [
            ['position' => 100.0, 'watched' => 100.0, 'counted' => true, 'source' => 'home', 'at' => new \DateTime('2026-10-04 09:00')],
            ['position' => 50.0, 'watched' => 50.0, 'counted' => true, 'source' => 'search', 'at' => new \DateTime('2026-10-03 22:00')],
            ['position' => 50.0, 'watched' => 40.0, 'counted' => true, 'source' => 'home', 'at' => new \DateTime('2026-08-01 10:00')],
            ['position' => 4.0, 'watched' => 4.0, 'counted' => false, 'source' => 'direct', 'at' => new \DateTime('2026-10-04 10:00')],
        ];
        $figures = WatchEventRepository::figures($rows, 100, 30, 4, $today);

        self::assertSame(3, $figures['views']);
        self::assertSame(4, $figures['sittings']);
        self::assertSame(48.5, $figures['average']);
        self::assertSame(0.25, $figures['completion']);
        self::assertSame([1.0, 0.75, 0.75, 0.25], $figures['retention'], 'the share of the sittings that reached each quarter');
        self::assertSame(['home' => 2, 'search' => 1], $figures['sources']);
        self::assertCount(30, $figures['days']);
        self::assertSame(1, $figures['days']['2026-10-04']);
        self::assertSame(1, $figures['days']['2026-10-03']);
        self::assertSame(2, array_sum($figures['days']), 'the view of August is out of the thirty days');
    }

    public function testNoSittingNoDivision(): void
    {
        $figures = WatchEventRepository::figures([], 0);
        self::assertSame(0, $figures['views']);
        self::assertSame(0.0, $figures['average']);
    }

    public function testTheSearchFilters(): void
    {
        self::assertSame([null, 240], VideoRepository::durationBounds('short'));
        self::assertSame([1200, null], VideoRepository::durationBounds('long'));
        self::assertNull(VideoRepository::dateSince('ever'));

        $clauses = VideoSearch::typesenseFilters(['category' => 'music', 'channel' => 'mire-signal', 'duration' => 'medium', 'date' => null]);
        self::assertSame(['category:=`music`', 'channelSlug:=`mire-signal`', 'duration:>=240', 'duration:<1200'], $clauses);
        self::assertSame(['category:=`ab`'], VideoSearch::typesenseFilters(['category' => 'a`b']), 'no way out of the quotes');
    }
}
