<?php

namespace Base\Video\Tests\Entity;

use Base\Video\Tests\Member;
use Base\Video\Entity\Playlist;
use Base\Video\Entity\Video;
use Base\Video\Entity\WatchEvent;
use PHPUnit\Framework\TestCase;

class PlaylistTest extends TestCase
{
    use \Base\Video\Tests\Films;

    private function video(int $id): Video
    {
        $video = self::film()->setVisibility(Video::VISIBILITY_PUBLIC);
        (new \ReflectionProperty(\Base\Entity\Thread::class, 'id'))->setValue($video, $id);

        return $video;
    }

    public function testAListKeepsItsOrderAndPlaysOn(): void
    {
        $list = new Playlist(Member::make(), 'Le soir');
        [$a, $b, $c] = [$this->video(1), $this->video(2), $this->video(3)];
        $list->add($a)->add($b)->add($c)->add($a);

        self::assertCount(3, $list->getItems(), 'a film once');
        self::assertSame([$a, $b, $c], $list->getVideos());
        self::assertSame($b, $list->next($a));
        self::assertNull($list->next($c));

        $list->reorder([3, 1]);
        self::assertSame([3, 1, 2], array_map(fn (Video $v) => $v->getId(), array_map(fn ($i) => $i->getVideo(), $this->sorted($list))));
        $list->remove($b);
        self::assertFalse($list->has($b));
    }

    public function testAFilmNoLongerReachableLeavesTheList(): void
    {
        $list = new Playlist(Member::make(), 'x');
        $list->add($a = $this->video(1))->add($b = $this->video(2));
        $b->setVisibility(Video::VISIBILITY_PRIVATE);
        self::assertSame([$a], $list->getVideos());
    }

    public function testLaterIsPrivateAndNewestFirst(): void
    {
        $later = new Playlist(Member::make(), 'Plus tard', Playlist::LATER);
        $later->setVisibility(Playlist::PUBLIC);
        self::assertSame(Playlist::PRIVATE, $later->getVisibility());
        $later->add($a = $this->video(1))->add($b = $this->video(2));
        self::assertSame([2, 1], array_map(fn ($i) => $i->getVideo()->getId(), $this->sorted($later)));
        self::assertFalse($later->isReachableBy(null));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16}$/', $later->getSlug());
    }

    public function testASittingsSecondsNeverRunAheadOfTheClock(): void
    {
        $video = $this->video(1)->setDuration(100);
        $event = new WatchEvent($video, 'abcdefgh', 'visitor', null, 'nowhere');
        self::assertSame('direct', $event->getSource(), 'an unknown source is "direct"');

        $event->progress(40.0, 5000.0, new \DateTime('+10 seconds'));
        self::assertLessThanOrEqual(30.0, $event->getWatched(), 'a forged "watched" is kept to the time elapsed');
        self::assertSame(40.0, $event->getPosition());
        $event->progress(10.0, 1.0, new \DateTime('+20 seconds'));
        self::assertSame(40.0, $event->getPosition(), 'the furthest point never goes back');
        $event->progress(500.0, 0.0, new \DateTime('+30 seconds'));
        self::assertSame(100.0, $event->getPosition(), 'nor past the end');
    }

    /** @return list<\Base\Video\Entity\PlaylistItem> */
    private function sorted(Playlist $list): array
    {
        $items = $list->getItems()->toArray();
        usort($items, fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

        return $items;
    }
}
