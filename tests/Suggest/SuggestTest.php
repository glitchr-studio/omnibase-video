<?php

namespace Base\Video\Tests\Suggest;

use Base\Video\Entity\Channel;
use Base\Video\Entity\Video;
use Base\Video\Repository\PlaylistRepository;
use Base\Video\Repository\RelatedRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Base\Video\Service\Views;
use Base\Video\Suggest\GorseSuggest;
use Base\Video\Suggest\LocalSuggest;
use Base\Video\Suggest\RelatedCalculator;
use Base\Video\Suggest\Suggestions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SuggestTest extends TestCase
{
    use \Base\Video\Tests\Films;

    private function video(int $id, ?Channel $channel = null, int $views = 0, string $published = '-10 days'): Video
    {
        $video = self::film()->setVisibility(Video::VISIBILITY_PUBLIC)->setChannel($channel)->setViews($views)->setProcessing(Video::PROCESSING_READY);
        $video->setPublishedAt(new \DateTime($published));
        (new \ReflectionProperty(\Base\Entity\Thread::class, 'id'))->setValue($video, $id);

        return $video;
    }

    private function channel(int $id): Channel
    {
        $channel = new Channel('Chaîne '.$id);
        (new \ReflectionProperty(Channel::class, 'id'))->setValue($channel, $id);

        return $channel;
    }

    private function calculator(int $perChannel = 2): RelatedCalculator
    {
        return new RelatedCalculator($this->createStub(EntityManagerInterface::class), $this->createStub(VideoRepository::class), $this->createStub(PlaylistRepository::class), $this->createStub(WatchEventRepository::class), [], $perChannel);
    }

    public function testAPairsScoreFollowsTheFormula(): void
    {
        $now = new \DateTime();
        $a = $this->video(1);
        $b = $this->video(2, null, 99, 'now');
        $score = $this->calculator()->pair($a, $b,
            ['channel:1' => 3.0, 'artist:7' => 3.0, 'list:4' => 2.0], ['channel:1' => 3.0, 'artist:7' => 3.0, 'list:4' => 2.0, 'list:9' => 2.0],
            ['jazz' => 0, 'live' => 1], ['live' => 0, 'rock' => 1],
            ['u1' => true, 'u2' => true], ['u2' => true, 'u3' => true],
            99, $now);

        // 3 (same channel or artist, once) + 2 (same list) + 1 (one keyword) + 2.5 x 1/2 (cosine) + 0.5 (fresh today) + 0.5 (the most seen)
        self::assertEqualsWithDelta(3 + 2 + 1 + 1.25 + 0.5 + 0.5, $score, 0.01);
    }

    public function testOnlyPairsThatShareSomethingAndTwoAChannel(): void
    {
        $one = $this->channel(1);
        $two = $this->channel(2);
        $videos = [$this->video(1, $one), $this->video(2, $one), $this->video(3, $one), $this->video(4, $one), $this->video(5, $two), $this->video(6, $two)];
        $groups = [];
        foreach ($videos as $video) {
            $groups[$video->getId()]['channel:'.$video->getChannel()->getId()] = 3.0;
        }
        // Film 1 and film 5 were watched by the same visitor: the only bridge between the channels.
        $rows = $this->calculator(2)->score($videos, $groups, [1 => ['v' => true], 5 => ['v' => true]], new \DateTime());

        $of = fn (int $id) => array_column(array_filter($rows, fn ($row) => $row[0] === $id), 1);
        self::assertCount(3, $of(1), 'two of its own channel at most, and the co-watched one');
        self::assertContains(5, $of(1));
        self::assertSame([5], $of(6), 'nothing in common with channel one');
        self::assertNotContains(1, $of(1), 'never itself');
        foreach ($rows as [, , $score]) {
            self::assertGreaterThan(0, $score);
        }
    }

    public function testNoCapOnABroadcastersSingleChannel(): void
    {
        $one = $this->channel(1);
        $videos = array_map(fn ($id) => $this->video($id, $one), range(1, 6));
        $groups = array_fill_keys(range(1, 6), ['channel:1' => 3.0]);
        $rows = $this->calculator(0)->score($videos, $groups, [], new \DateTime());
        self::assertCount(30, $rows, 'each of the six keeps its five neighbours');
    }

    public function testTheLocalListsCapAChannel(): void
    {
        $local = new LocalSuggest($this->createStub(RelatedRepository::class), $this->createStub(VideoRepository::class), $this->createStub(WatchEventRepository::class), 2);
        $one = $this->channel(1);
        $videos = [$a = $this->video(1, $one), $b = $this->video(2, $one), $this->video(3, $one), $d = $this->video(4, $this->channel(2)), $a];
        self::assertSame([$a, $b, $d], $local->cap($videos, 10));
        self::assertCount(4, $local->cap($videos, 10, 0), 'no cap: each once');
    }

    public function testGorseAnswersAreReadAndItsFailuresFallBack(): void
    {
        $listed = [$this->video(12), $this->video(7)];
        $videos = $this->createStub(VideoRepository::class);
        $videos->method('findListedByIds')->willReturnCallback(fn (array $ids) => array_values(array_filter($listed, fn (Video $v) => \in_array($v->getId(), $ids, true))));
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$method, $url, $options['normalized_headers']['x-api-key'][0] ?? null, $options['body'] ?? null];

            return new MockResponse(str_contains($url, 'neighbors') ? '[{"Id":"12","Score":0.9},{"Id":"7","Score":0.4},{"Id":"99","Score":0.1}]' : '["7","12"]');
        });
        $gorse = new GorseSuggest($client, $videos, 'http://suggest:8088/', 'k3y');

        self::assertSame([12, 7], array_map(fn (Video $v) => $v->getId(), $gorse->similar($this->video(1), 5)), 'a film no longer listed is left out');
        self::assertSame('GET', $requests[0][0]);
        self::assertStringStartsWith('http://suggest:8088/api/item/1/neighbors', $requests[0][1]);
        self::assertSame('X-API-Key: k3y', $requests[0][2]);

        $gorse->send('watch', '4', 12, new \DateTimeImmutable('2026-10-04T12:00:00+00:00'));
        self::assertSame('POST', $requests[1][0]);
        self::assertStringContainsString('"FeedbackType":"watch","UserId":"4","ItemId":"12","Timestamp":"2026-10-04T12:00:00+00:00"', (string) $requests[1][3]);
        self::assertSame([12, 7], GorseSuggest::ids([['Id' => '12'], '7', ['Score' => 1]]));

        // Gorse down: the local suggestions answer, nothing throws.
        $down = new GorseSuggest(new MockHttpClient(new MockResponse('', ['http_code' => 500])), $videos, 'http://suggest:8088', '');
        $related = $this->createStub(RelatedRepository::class);
        $related->method('findFor')->willReturn($listed);
        $local = new LocalSuggest($related, $videos, $this->createStub(WatchEventRepository::class), 0);
        $suggestions = new Suggestions($local, $down, 'gorse');
        self::assertTrue($suggestions->usesGorse());
        self::assertSame($listed, $suggestions->similar($this->video(1), 2));

        self::assertFalse((new Suggestions($local, new GorseSuggest(new MockHttpClient(), $videos, '', ''), 'gorse'))->usesGorse(), 'no address: Gorse is off whatever the provider');
    }

    public function testAViewNeedsThirtySecondsOrHalfTheFilm(): void
    {
        $views = (new \ReflectionClass(Views::class))->newInstanceWithoutConstructor();
        foreach (['minSeconds' => 30, 'ratio' => 0.5] as $property => $value) {
            (new \ReflectionProperty(Views::class, $property))->setValue($views, $value);
        }
        self::assertSame(30.0, $views->threshold(600));
        self::assertSame(10.0, $views->threshold(20), 'half of a short film');
        self::assertSame(30.0, $views->threshold(null), 'a length unknown');
    }
}
