<?php

namespace Base\Video\Tests\Service;

use Base\Video\Exception\RemoteException;
use Base\Video\Service\RemoteManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RemoteManifestTest extends TestCase
{
    private const MASTER = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=778984,RESOLUTION=1024x576\nlow/chunklist.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=6425079,RESOLUTION=1280x720\nhttps://media.example.tv/vod/hd/chunklist.m3u8\n#EXT-X-MEDIA:TYPE=AUDIO,URI=\"/audio/fr.m3u8\"\n";

    public function testUrisBecomeAbsoluteAgainstTheOrigin(): void
    {
        $out = RemoteManifest::absolutize(self::MASTER, 'https://origin.example.com/videos/10644');
        self::assertStringContainsString("\nhttps://origin.example.com/videos/low/chunklist.m3u8\n", $out);
        self::assertStringContainsString("\nhttps://media.example.tv/vod/hd/chunklist.m3u8\n", $out, 'an absolute one is left alone');
        self::assertStringContainsString('URI="https://origin.example.com/audio/fr.m3u8"', $out);
        self::assertSame(['https://origin.example.com/videos/low/chunklist.m3u8', 'https://media.example.tv/vod/hd/chunklist.m3u8'], RemoteManifest::variants($out));
    }

    public function testWhatIsNotAPlaylistIsRefused(): void
    {
        $this->expectException(RemoteException::class);
        RemoteManifest::absolutize('<html>not found</html>', 'https://origin.example.com/x');
    }

    public function testTheLengthIsTheSegmentsSummed(): void
    {
        self::assertSame(205, RemoteManifest::sum("#EXTM3U\n#EXTINF:10.0,\nmedia_0.ts\n#EXTINF:10.0,\nmedia_1.ts\n#EXTINF:185.4,\nmedia_2.ts\n"));
    }

    public function testOnlyTheAllowedHostsAreRead(): void
    {
        $manifest = new RemoteManifest(new MockHttpClient(), new ArrayAdapter(), ['example.com']);
        self::assertTrue($manifest->allows('https://example.com/videos/1'));
        self::assertTrue($manifest->allows('https://cdn.example.com/videos/1'));
        self::assertFalse($manifest->allows('https://example.com.evil.net/videos/1'));
        self::assertFalse($manifest->allows('http://example.com/videos/1'), 'https only');
        self::assertFalse((new RemoteManifest(new MockHttpClient(), new ArrayAdapter(), []))->allows('https://example.com/a'), 'no host listed: nothing is read');

        $this->expectException(RemoteException::class);
        $manifest->master('https://169.254.169.254/latest/meta-data');
    }

    public function testTheMasterIsReadOnceThenKept(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$calls) {
            ++$calls;

            return str_contains($url, 'chunklist')
                ? new MockResponse("#EXTM3U\n#EXTINF:10.0,\na.ts\n#EXTINF:8.5,\nb.ts\n")
                : new MockResponse(self::MASTER);
        });
        $manifest = new RemoteManifest($client, new ArrayAdapter(), ['example.com', 'example.tv']);

        $first = $manifest->master('https://origin.example.com/videos/10644');
        $manifest->master('https://origin.example.com/videos/10644');
        self::assertSame(1, $calls, 'the second reading comes from the cache');
        self::assertStringStartsWith('#EXTM3U', $first);
        self::assertSame(19, $manifest->duration('https://origin.example.com/videos/10644'));
    }

    public function testAnOriginThatFailsIsARemoteException(): void
    {
        $manifest = new RemoteManifest(new MockHttpClient(new MockResponse('', ['http_code' => 503])), new ArrayAdapter(), ['example.com']);
        self::assertNull($manifest->duration('https://example.com/videos/1'));
        $this->expectException(RemoteException::class);
        $manifest->master('https://example.com/videos/1');
    }
}
