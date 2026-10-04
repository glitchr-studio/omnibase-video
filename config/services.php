<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Everything in src/ is a plain autowired service, the way an application's
 * own src/ is. The back office's screens and tiles are loaded only when
 * omnibase/admin is installed.
 */
return function (ContainerConfigurator $configurator) {
    $src = dirname(__DIR__).'/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Video\\', $src.'/')
        ->exclude([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Exception/',
            $src.'/Message/',
            $src.'/Model/',
            $src.'/Search/SafeIndexer.php',
            $src.'/Controller/Admin/',
            $src.'/Admin/',
            $src.'/VideoBundle.php',
        ]);

    $services->load('Base\\Video\\Controller\\Client\\', $src.'/Controller/Client/')
        ->tag('controller.service_arguments');

    // The search index never fails a save (Search\SafeIndexer over typesense-bundle's listener).
    $services->set('video.search.indexer', 'Base\\Video\\Search\\SafeIndexer')
        ->decorate('typesense.listener.doctrine_indexer')
        ->autowire(false)->autoconfigure(false)
        ->args([service('typesense_manager'), service('request_stack'), service('parameter_bag')])
        ->call('setLogger', [service('logger')->nullOnInvalid()]);

    // The suggestions: the provider configured (video.suggest.provider), the local one behind it.
    $services->alias('Base\\Video\\Suggest\\SuggestInterface', 'Base\\Video\\Suggest\\Suggestions');

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
        $services->load('Base\\Video\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
        $services->load('Base\\Video\\Admin\\', $src.'/Admin/');
    }
};
