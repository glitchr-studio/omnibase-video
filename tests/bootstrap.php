<?php

// Standalone (composer install in this checkout), inside a host application
// (vendor/omnibase/video), or as a path repository symlinked into one (the
// application's autoloader named by VIDEO_AUTOLOAD, /srv/app by default):
// whichever autoloader exists is used, and the test namespace is registered
// by hand - a host's autoloader never reads a dependency's autoload-dev.
$candidates = array_filter([
    getenv('VIDEO_AUTOLOAD') ?: null,
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../autoload.php',
    '/srv/app/vendor/autoload.php',
]);
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Base\\Video\\Tests\\', __DIR__);
        // Prepended: the classes under test are this checkout's.
        $loader->addPsr4('Base\\Video\\', __DIR__.'/../src', true);

        return;
    }
}

throw new RuntimeException('No autoloader found: run composer install in this checkout or install the bundle in an application.');
