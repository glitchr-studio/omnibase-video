<?php

namespace Base\Video\Tests\Entity;

use Base\Enum\ThreadState;
use Base\Video\Entity\Source;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use PHPUnit\Framework\TestCase;

class VideoTest extends TestCase
{
    use \Base\Video\Tests\Films;

    public function testTheVisibilityIsTheThreadsState(): void
    {
        $video = self::film();
        self::assertSame(Video::VISIBILITY_PRIVATE, $video->getVisibility(), 'a new film is a private draft');

        $video->setVisibility(Video::VISIBILITY_PUBLIC);
        self::assertSame(ThreadState::PUBLISH, $video->getState());
        self::assertNotNull($video->getPublishedAt());
        self::assertTrue($video->isListed());

        $video->setVisibility(Video::VISIBILITY_UNLISTED);
        self::assertSame(ThreadState::SECRET, $video->getState());
        self::assertFalse($video->isListed());
        self::assertTrue($video->isReachable(), 'reachable by its address');

        $video->setVisibility(Video::VISIBILITY_SCHEDULED, new \DateTime('+2 days'));
        self::assertSame(ThreadState::FUTURE, $video->getState());
        self::assertFalse($video->isReachable());

        $video->setVisibility(Video::VISIBILITY_SCHEDULED, new \DateTime('-1 hour'));
        self::assertSame(Video::VISIBILITY_PUBLIC, $video->getVisibility(), 'a date already past publishes now');

        $this->expectException(\InvalidArgumentException::class);
        $video->setVisibility('hidden');
    }

    public function testATakenDownFilmIsOutOfSightWithItsReason(): void
    {
        $video = self::film()->setVisibility(Video::VISIBILITY_PUBLIC);
        $video->takeDown('Atteinte au droit d\'auteur.');
        self::assertTrue($video->isTakenDown());
        self::assertFalse($video->isReachable());
        self::assertSame('Atteinte au droit d\'auteur.', $video->getTakedownReason());
        $video->restore();
        self::assertFalse($video->isTakenDown());
        self::assertSame(Video::VISIBILITY_PRIVATE, $video->getVisibility(), 'restored private: its author publishes again');
    }

    public function testThePlayerStartsFromTheMasterNotARendition(): void
    {
        $video = self::film();
        self::assertFalse($video->isPlayable());
        $video->addSource((new Source(Source::HLS, '/media/videos/k/720/index.m3u8', Source::RENDITION))->setSize(1280, 720));
        $video->addSource($master = new Source(Source::HLS, '/media/videos/k/master.m3u8'));
        self::assertSame($master, $video->getPlayableSource());
        self::assertCount(1, $video->getRenditions());
        self::assertFalse($video->isPlayable(), 'not before its transcoding is done');
        $video->setProcessing(Video::PROCESSING_READY);
        self::assertTrue($video->isPlayable());

        $remote = self::film()->addSource(new Source(Source::REMOTE_HLS, 'https://origin.example.com/videos/1'));
        self::assertTrue($remote->isPlayable(), 'a remote film has nothing to transcode');
        self::assertTrue($remote->isRemote());
    }

    public function testAnOutsideSourceKeepsItsReferenceOnly(): void
    {
        $source = (new Source(Source::EMBED, 'https://vimeo.com/76979871'))->setReference('vimeo', 'https://player.vimeo.com/video/76979871');
        self::assertSame('vimeo', $source->getPlatform());
        self::assertSame('76979871', $source->getExternalId());
        self::assertTrue($source->isAbsolute());
        self::assertSame('dQw4w9WgXcQ', (new Source(Source::EMBED, 'x'))->setReference('youtube', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')->getExternalId());
    }

    public function testTimesAndViews(): void
    {
        self::assertSame('0:07', Video::formatTime(7));
        self::assertSame('4:05', Video::formatTime(245));
        self::assertSame('1:02:03', Video::formatTime(3723));
        self::assertSame('', Video::formatTime(null));
        $video = self::film()->setViews(12)->setLegacyViews(687248);
        self::assertSame(687260, $video->getTotalViews());
        self::assertSame('16 / 9', $video->getRatio());
    }

    public function testTheMomentsTypedInAComment(): void
    {
        self::assertSame([83, 3723, 5], VideoComment::timecodes('À 1:23 puis 1:02:03, et au tout début 0:05 !'));
        self::assertSame([], VideoComment::timecodes('Le score : 12:75, la référence 2026:10:04:12.'), 'not a time: seconds over 59, or a longer number');
        $comment = (new VideoComment())->setMoment(83);
        self::assertSame('1:23', $comment->getMomentText());
        self::assertNull($comment->setMoment(-4)->getMoment());
    }
}
