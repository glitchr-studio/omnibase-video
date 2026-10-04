<?php

namespace Base\Video\Tests;

use Base\Entity\Thread;
use Base\Enum\ThreadState;
use Base\Video\Entity\Video;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * A Video for a unit test: omnibase's Thread writes its title through the
 * translation services in its constructor, which a test without a kernel
 * does not have - so it is made without it, its collections and state set.
 */
trait Films
{
    protected static function film(?int $id = null): Video
    {
        $video = (new \ReflectionClass(Video::class))->newInstanceWithoutConstructor();
        foreach (['sources' => Video::class, 'tags' => Thread::class, 'owners' => Thread::class, 'likes' => Thread::class] as $property => $class) {
            (new \ReflectionProperty($class, $property))->setValue($video, new ArrayCollection());
        }
        (new \ReflectionProperty(Thread::class, 'state'))->setValue($video, ThreadState::DRAFT);
        if (null !== $id) {
            (new \ReflectionProperty(Thread::class, 'id'))->setValue($video, $id);
        }

        return $video;
    }
}
