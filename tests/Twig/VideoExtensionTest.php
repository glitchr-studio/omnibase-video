<?php

namespace Base\Video\Tests\Twig;

use Base\Video\Twig\VideoExtension;
use PHPUnit\Framework\TestCase;

class VideoExtensionTest extends TestCase
{
    private function extension(): VideoExtension
    {
        return (new \ReflectionClass(VideoExtension::class))->newInstanceWithoutConstructor();
    }

    public function testTimecodesBecomeLinksAndTheTextIsEscaped(): void
    {
        $extension = $this->extension();
        (new \ReflectionProperty(VideoExtension::class, 'urls'))->setValue($extension, $this->createStub(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class));
        $html = $extension->timecodes("<b>À 1:23</b>\nvoir https://example.org/a?b=1&c=2");

        self::assertStringContainsString('&lt;b&gt;À <a class="video-timecode" href="?t=83" data-seek="83">1:23</a>&lt;/b&gt;', $html);
        self::assertStringContainsString("<br />\n", $html);
        self::assertStringContainsString('<a href="https://example.org/a?b=1&amp;c=2" rel="nofollow ugc noopener" target="_blank">', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testCountsAreCompact(): void
    {
        $extension = $this->extension();
        self::assertSame('0', $extension->count(0, 'fr'));
        self::assertSame("9\u{202F}999", $extension->count(9999, 'fr'));
        self::assertSame('12 k', $extension->count(12400, 'fr'));
        self::assertSame('687 k', $extension->count(687248, 'fr'));
        self::assertSame('1,2 M', $extension->count(1200000, 'fr'));
        self::assertSame('1.2 M', $extension->count(1200000, 'en'));
        self::assertSame('9,999', $extension->count(9999, 'en'));
    }

    public function testAnExcerptCutsAtAWord(): void
    {
        self::assertSame('Court.', VideoExtension::excerpt('<p>Court.</p>'));
        $excerpt = VideoExtension::excerpt("Douze heures de ciel au-dessus des toits,\n ramenées à une minute.", 40);
        self::assertSame('Douze heures de ciel au-dessus des…', $excerpt);
        self::assertLessThanOrEqual(40, mb_strlen($excerpt));
    }
}
