<?php

namespace Base\Video\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class VideoExtension extends AbstractBaseExtension implements PrependExtensionInterface
{
    public function getConfiguration(array $config, ContainerBuilder $container): VideoConfiguration
    {
        return new VideoConfiguration();
    }

    /**
     * The limiters a member meets (symfony/rate-limiter): comments, ratings,
     * uploads, reports - an application redefines any of them under the same
     * name in its framework.rate_limiter -, and the bundle's cache pool.
     */
    public function prepend(ContainerBuilder $container): void
    {
        // video.cache: the remote masters read, the visitors already counted.
        // On cache.app by default; a site points it at Redis in its
        // framework.cache.pools (adapter: cache.adapter.redis).
        $container->prependExtensionConfig('framework', [
            'cache' => ['pools' => ['video.cache' => ['adapter' => 'cache.app']]],
        ]);
        $container->prependExtensionConfig('framework', [
            'rate_limiter' => [
                'video_comment' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '10 minutes'],
                'video_rate' => ['policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 minute'],
                'video_upload' => ['policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 day'],
                'video_report' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $configuration = new VideoConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // Flat parameters: video.upload.enabled, video.suggest.provider...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());
    }
}
