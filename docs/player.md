---
title: The player
order: 6
---

# The player

```twig
{# a film's page #}
{% include '@Video/client/_player.html.twig' with {video: video, resume: seconds, source: 'home', autoplay: false} %}
{# the layout, outside #content #}
{% include '@Video/client/_dock.html.twig' %}
```

The first is a **stage** (`[data-video-player]`, its `data-*` say what to
play); the second the **dock**: one `<media-controller>` for the whole
visit. On each page (`load`, `transparent:load`) `assets/player.js` looks
for a stage:

- the film it already plays: it lays itself over the stage again;
- another film: it loads it there;
- no stage and a film playing: it shrinks into a corner (the mini-player:
  title, play/pause, close);
- no stage, nothing playing: it hides.

It resumes (`?t=` first, then the member's place on the server, then this
browser's), seeks on `a[data-seek]` (the comments' and descriptions'
moments: the `|timecodes(video)` filter), copies the link at the current
second (`[data-video-share]`), has a cinema mode (`[data-video-cinema]`,
`html.video-cinema`), speed and quality menus, a storyboard on the time bar,
the keyboard of media-chrome.

A film's page (`@Video/client/watch.html.twig`) leaves two blocks to a
site: `video_channel` (the channel and its subscribe button - emptied on a
single-show site) and `video_extra` (under the description).

## Engines

The player's commands never touch a `<video>`: they talk to an engine.

```js
VideoPlayer.registerEngine('youtube', function (host, data) {
    return {
        load(data) {},              // src, platform, externalId, poster, start...
        play() {}, pause() {}, seek(seconds) {},
        time() {}, duration() {}, paused() {}, rate() {},
        on(event, fn) {},           // 'ready' | 'play' | 'pause' | 'time' | 'seeking' | 'end'
        destroy() {},
    };
});
```

The native engine (`hls`, `mp4`) is the bundle's. A stage whose `data-type`
names a registered engine gets it. The beacons, the resume, the mini-player
and the timecodes work with any engine that gives the time.

## One thing at a time: `media:play`

When it starts, the player dispatches on `document`:

```js
new CustomEvent('media:play', { detail: { source: 'video', id, element } })
```

and it pauses when anything else on the page dispatches one (another
player, an audio bar).

## Remote HLS, without a copy

A `Source` of kind `remote-hls` holds a master playlist's address on
another origin. `GET /v/{slug}/master.m3u8` (`video_manifest`) reads it on
the server - only from `video.remote.hosts`, https - keeps it
`video.remote.ttl` seconds and serves it with its URIs made absolute: the
browser fetches the variant playlists and the segments from the origin
itself. Nothing else of the film passes through the site.
`RemoteManifest::duration()` sums a variant's `#EXTINF`.

## Outside sources

A `Source` of kind `embed` keeps the film's page address and its reference
- `getPlatform()`, `getExternalId()` - and no logic. Until an engine is
registered for its platform it plays in the platform's own frame
(omnibase's `embed_url()`), loaded only once the visitor accepted the
`EMBEDS` feature of omnibase/consent. Members add one by its link in the
studio (`/studio/lien`).

## Beacons

`POST /v/{slug}/watch` with `{token, event: start|progress|end, position,
watched, source, referrer}`: on the first play, every ten seconds, on
pause, at the end, and when the page goes. `watched` is seconds really
played (a seek is not watching).
