<?php

namespace Base\Video\Tests\Player;

use PHPUnit\Framework\TestCase;

/**
 * One thing sounds at a time through glitchr/omnibase's media:play module:
 * the player joins and announces itself with MediaPlay, and keeps no event
 * listener nor dispatch of its own; the dock links the module and keeps its
 * own <video> out of the plain elements the module watches.
 */
class MediaPlayTest extends TestCase
{
    public function testThePlayerTakesPartThroughMediaPlay(): void
    {
        $player = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/player.js');

        $this->assertStringContainsString('window.MediaPlay.join(VideoPlayer,', $player);
        $this->assertStringContainsString('window.MediaPlay.announce(VideoPlayer,', $player);
        $this->assertStringNotContainsString("addEventListener('media:play'", $player, 'no listener of its own');
        $this->assertStringNotContainsString("new CustomEvent('media:play'", $player, 'no dispatch of its own');
    }

    public function testTheDockLinksTheModuleAndKeepsItsVideoOutOfThePlainElements(): void
    {
        $dock = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/client/_dock.html.twig');

        $this->assertStringContainsString("asset('bundles/base/js/media.js')", $dock);
        $this->assertMatchesRegularExpression('/<div id="video-dock"[^>]*data-media-play="off"/', $dock);
    }
}
