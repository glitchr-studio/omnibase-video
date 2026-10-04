---
title: Transcoding
order: 5
---

# Transcoding

`TranscodeVideoHandler` -> `Service\Processing::transcode()` ->
`Service\Transcoder` (ffmpeg through symfony/process; the `*Command()`
methods only assemble arguments and are tested as they are).

A video's directory, `<video.storage>/<random key>/`:

| File | |
|---|---|
| `master.m3u8` | the HLS master, one variant a rung |
| `<height>/index.m3u8`, `init_N.mp4`, `seg_000.m4s`... | each rung, fMP4, a key frame on every segment's start |
| `poster.jpg` | the still shown before it plays |
| `frame-1.jpg`... | stills the author picks the poster from |
| `storyboard.jpg`, `storyboard.vtt` | the time bar's previews: a sprite and its `#xywh` boxes |

- The ladder is `video.transcode.renditions` not above the source: a 720p
  film gets 720, 480, 360; a film smaller than every rung keeps its own
  height; a portrait film is laddered on its short side.
- One ffmpeg pass for the whole ladder (`split`, one `scale` a rung,
  `-var_stream_map`, `-master_pl_name`).
- A replacement is built **beside** the old ladder and swapped in when
  whole: the film keeps playing meanwhile, its address, views and comments
  stay.
- States (`Video::getProcessing()`): `uploaded` -> `running` -> `ready`, or
  `failed` with `getFailure()`; `none` for a remote or embedded film. Its
  authors are told in omnibase's notification centre.
- The original is removed once the ladder exists (`keep_originals: 0`).

```bash
bin/console video:transcode <id|slug> /path/to/film.mov   # here and now: a repair, an import
```
