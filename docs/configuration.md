---
title: Configuration
order: 3
---

# Configuration

Every leaf is also a parameter of the same name (`%video.upload.enabled%`).

```yaml
# config/packages/video.yaml
video:
    storage: '%kernel.project_dir%/var/storage/videos'   # one directory a video, named by a random key
    public_path: /media/videos                           # where the web server serves it

    upload:
        enabled: true          # false: a broadcaster's site - no upload page, tusd's hooks refuse
        auto_publish: false    # true: a converted upload goes public at once
        quota: 21474836480     # bytes of originals a member may have sent; 0: none
        max_size: 8589934592   # bytes a file
        verified_only: true    # only a member whose e-mail is verified
        directory: '%kernel.project_dir%/var/storage/uploads'   # tusd's -upload-dir, as the site sees it
        endpoint: /files/
        token_ttl: 86400       # seconds an upload token lives
        keep_originals: 0      # 0: the original is removed once converted

    transcode:
        ffmpeg: ffmpeg
        ffprobe: ffprobe
        renditions: [1080, 720, 480, 360]
        segment: 6             # seconds
        preset: veryfast
        timeout: 14400
        thumbnails: 100        # pictures of the storyboard, at most
        frames: 4              # stills offered as a poster

    views:
        min_seconds: 30        # a view after min(min_seconds, ratio x duration) really played
        ratio: 0.5
        dedupe: 21600          # once a visitor and film every six hours

    comments:
        auto_approve: true     # a comment Akismet finds clean goes online at once
        guests: false          # visitors without an account may comment

    search:
        external: false        # reserved (searching other platforms is not this bundle's)

    suggest:
        provider: local        # local | gorse
        gorse_url: ''          # http://suggest:8088 ; empty: Gorse is off whatever the provider
        gorse_key: ''
        per_channel: 2         # 0: no cap (a single-channel site)

    remote:
        hosts: []              # hosts a "remote-hls" master may be read from (https only)
        ttl: 3600

    categories: [music, film, animation, documentary, education, science, sport, games, travel, cooking, news, comedy, diy, other]
    licenses: [standard, cc-by, cc-by-sa, cc-by-nc, cc0]
```

The bundle also declares (override them under the same names):

- the cache pool `video.cache` (on `cache.app`; point it at Redis:
  `framework.cache.pools.video.cache: {adapter: cache.adapter.redis, provider: ...}`);
- four limiters: `video_comment` (10 / 10 min), `video_rate` (60 / min),
  `video_upload` (20 / day), `video_report` (10 / hour).

Translations: domain `video` (fr, en). A category added to `categories`
needs its `category.<key>`.
