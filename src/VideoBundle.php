<?php

namespace Base\Video;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A video platform on top of omnibase's Thread: a Video IS a thread (a
 * JOINED subclass, so it has its title, text, slug, owners, tags, likes,
 * publication states and trash); a channel, a source, a playlist, a watch
 * and a subscription are plain rows. A comment is omnibase's own, with the
 * moment of the film it was written at.
 */
class VideoBundle extends AbstractBaseBundle
{
    use SingletonTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /** Modern layout: the class lives in src/, the bundle root is the package root. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // App\-wins, as omnibase does for its own entities: an application may
        // declare App\Entity\Video\Video extending ours and take over.
        $this->setMapping($this->getPath().'/src/Entity', 'Base\Video\Entity', 'App\Entity\Video');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Video\Repository', 'App\Repository\Video');
    }
}
