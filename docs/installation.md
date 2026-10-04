---
title: Installation
order: 2
---

# Installation

```bash
composer require omnibase/video:dev-main
```

```php
// config/bundles.php
Typesense\Bundle\TypesenseBundle::class => ['all' => true],
Base\Video\VideoBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
video_controller:
    resource: "@VideoBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

```yaml
# config/packages/messenger.yaml - the bundle's messages go to the worker
framework:
    messenger:
        routing:
            Base\Video\Message\TranscodeVideo: async
            Base\Video\Message\FlushViews: async
            Base\Video\Message\ComputeRelated: async
            Base\Video\Message\SuggestFeedback: async
```

Then a migration (`doctrine:migrations:diff`, `migrate`): the tables
`video`, `video_source`, `video_channel`, `video_channel_owner`,
`video_subscription`, `video_comment`, `video_watch`, `video_playlist`,
`video_playlist_item`, `video_related`.

## The layout

The player lives **outside** what transparent.js swaps (`#content`):

```twig
{# templates/layout/layout1.html.twig #}
<link rel="stylesheet" href="{{ asset('bundles/video/css/video.css') }}">
...
<main id="content">{% block content %}{% endblock %}</main>
...
{% include '@Video/client/_dock.html.twig' %}
```

The bundle's pages extend `layout1.html.twig` and fill its `content`,
`title` and `description` blocks. Colours and fonts are custom properties
(`--video-bg`, `--video-surface`, `--video-ink`, `--video-soft`,
`--video-line`, `--video-accent`, `--video-on-accent`, `--video-radius`,
`--video-font`, `--video-font-display`...): set them on your `:root`, for
each theme.

## The front end

The bundle's scripts import no package: the application does, and hands
them over.

```bash
yarn add media-chrome hls-video-element @uppy/core @uppy/dashboard @uppy/tus
```

```js
// assets/app-defer.js
import 'media-chrome';
import 'media-chrome/menu';
import 'media-chrome/lang/fr';
import 'hls-video-element';
import VideoPlayer from '../vendor/omnibase/video/assets/player.js';

import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import Tus from '@uppy/tus';
import '@uppy/core/dist/style.min.css';
import '@uppy/dashboard/dist/style.min.css';
import Studio from '../vendor/omnibase/video/assets/studio.js';

Base.boot({ exceptions: ['/files/*', '/media/*', '/v/*/master.m3u8'] });
VideoPlayer.start();
Studio.start({ Uppy, Dashboard, Tus });   // only where members upload
```

A content security policy needs `blob:` in `media-src` (hls.js plays from
MediaSource).

## The containers

- **web / worker**: ffmpeg and ffprobe in the image; the worker consumes
  `async`.
- **upload** (tusd), **search** (Typesense): see [Uploads](upload.md) and
  [Search](search.md).
- The web server serves the storage:

```nginx
location ^~ /media/videos/ {
    alias /srv/app/var/storage/videos/;
    types { application/vnd.apple.mpegurl m3u8; video/mp4 mp4 m4s; text/vtt vtt; image/jpeg jpg; }
    add_header Access-Control-Allow-Origin * always;
}
```

- **cron** (no symfony/scheduler: cron dispatches, Messenger works):

```
*/5 * * * *  php bin/console video:views:flush --async
30  3 * * *  php bin/console video:related --async
0   4 * * *  php bin/console video:uploads:clean
```

web, worker and tusd must see the same `var/storage` (uploads and videos).
