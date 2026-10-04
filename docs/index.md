---
title: omnibase/video
order: 1
---

# omnibase/video

Hosted video for an omnibase site: a platform where members publish
(uploads on), or a broadcaster's site whose films come from the back office
or an import (uploads off).

| Page | |
|---|---|
| [Installation](installation.md) | the bundle, the routes, the layout, the front-end packages, the containers |
| [Configuration](configuration.md) | every `video.*` option |
| [Uploads](upload.md) | tusd, the upload token, the two hooks |
| [Transcoding](transcoding.md) | the ladder, the files written, the worker |
| [The player](player.md) | the dock, engines, `media:play`, beacons, remote HLS, outside sources |
| [Views and suggestions](views-and-suggestions.md) | the view rule, the flush, `Related`, Gorse |
| [Search](search.md) | the Typesense mapping, the fallback |
| [Moderation and back office](moderation.md) | comments, reports, take-downs, roles, screens |

## What is whose

| Thing | Where |
|---|---|
| Thread, likes, comments, their guard and form, reports, trash, `embed_url()` | glitchr/omnibase |
| CRUD screens, dashboard tiles, settings sections, the reports' screen | omnibase/admin |
| `Video`, `Source`, `Channel`, `Subscription`, `VideoComment` (the moment), `WatchEvent`, `Playlist`, `Related` | this bundle |
| Searching and playing other platforms' films (YouTube, Vimeo...) | not here: a `Source` keeps a reference (platform + id); the engines are another family's |
