---
title: Views and suggestions
order: 7
---

# Views

A beacon lands on a `WatchEvent` (one a sitting). It becomes a **view**
once `min(video.views.min_seconds, video.views.ratio x duration)` seconds
were really played, and only once per visitor and film every
`video.views.dedupe` seconds (the pool `video.cache`). The visitor is the
account, or a keyed hash of the address and the browser - never stored
readable. The server does not trust `watched`: it cannot grow faster than
the clock.

`video:views:flush` (cron, every five minutes, `--async`) adds the counted
views to `Video::$views` in one statement a film. `Video::$legacyViews`
keeps the views a film had where it was imported from;
`getTotalViews()` is what a card shows.

The same rows are a member's history (`/historique`, erasable) and the
studio's figures (`WatchEventRepository::stats()`): views, average
watched, completion, the retention curve, thirty days of views, where they
came from (home, search, channel, playlist, suggest, subscriptions,
history, external, direct).

# Suggestions

```php
interface SuggestInterface
{
    public function feedback(string $type, User $user, Video $video): void;  // read | watch | like
    public function recommend(User $user, int $limit = 24): array;           // a member's feed
    public function session(array $videoIds, int $limit = 24): array;        // a visitor's, from what they watched
    public function similar(Video $video, int $limit = 12): array;           // "à suivre"
}
```

Inject `SuggestInterface`: it is `Suggestions`, which asks Gorse when
`provider: gorse` and it answers, and the local ones otherwise.

## Local (always there)

`video:related` (cron, at night) writes `Related`: for two listed films,

```
3   · same channel, or an affinity's 3.0 group (same artist)
+ 2 · same public list, or an affinity's 2.0 group (same episode)
+ 1 · each keyword in common (three at most)
+ 2.5 · co-watching (cosine of their audiences, ninety days)
+ 0.5 · freshness (e^(-age / 30 days))
+ 0.5 · popularity (log of the views against the most seen)
```

twenty-four neighbours a film, `per_channel` of one channel at most. A
feed is the neighbours of what was watched last, the seen ones left out,
one in five from the trends.

Another bundle adds what makes films close with an
`AffinityInterface` service (autoconfigured):

```php
public function groups(array $videos): array   // [videoId => ['artist:12' => 3.0, 'episode:5' => 2.0]]
```

## Gorse (optional)

[gorse-in-one](https://gorse.io) over its REST API (symfony/http-client):
`POST /api/feedback` (through Messenger: `SuggestFeedback`),
`GET /api/recommend/{user}`, `POST /api/session/recommend`,
`GET /api/item/{id}/neighbors`. In gorse-in-one the API is on the master's
HTTP port (8088). The key is `video.suggest.gorse_key`, or `api.gorse.key`
typed in the back office. Any failure, any short answer: the local
suggestions fill in.
