---
title: Uploads
order: 4
---

# Uploads

Films never go through PHP. The studio's page mounts
[Uppy](https://uppy.io) with its tus plugin; the browser talks to
[tusd](https://github.com/tus/tusd) through the proxy at `/files/`; tusd
writes into `video.upload.directory` and asks the site twice.

```yaml
# docker-compose.yml
upload:
    image: tusproject/tusd:v2.8.0
    command:
      - -upload-dir=/srv/app/var/storage/uploads
      - -base-path=/files/
      - -behind-proxy
      - -hooks-http=http://web/video/upload/hook
      - -hooks-enabled-events=pre-create,post-finish
      - -hooks-http-forward-headers=X-Forwarded-Host,X-Forwarded-Proto,X-Forwarded-Port,X-Forwarded-For
    volumes:
      - storage:/srv/app/var/storage:rw     # the same volume as web and worker
```

```nginx
# the proxy
location ^~ /files/ {
    client_max_body_size 0;
    proxy_request_buffering off;
    proxy_pass http://upload:8080;
}
location ^~ /video/upload/hook { return 404; }   # tusd reaches it from inside only
```

omnibase answers only the hosts it knows: the internal name tusd calls
(`web`) must be among them (`HTTP_DOMAIN`).

## The token

`Uploads::token($user, ?$replace)` (Twig: `video_upload_token()`): who,
until when, and - to replace a film's file - which video; signed with the
kernel secret. Uppy sends it in the upload's metadata. Whoever calls the
hook, only a valid token makes it act.

## The hooks (`POST /video/upload/hook`)

| tusd asks | the site answers |
|---|---|
| `pre-create` | `{}` or `{RejectUpload: true, HTTPResponse: {StatusCode: 403, Body: "upload.refused.<why>"}}`: `token`, `account` (banned, locked), `verified`, `size`, `quota`, `limit` (the `video_upload` limiter), `replace` (not their film); 404 `disabled` when uploads are off |
| `post-finish` | the `Video` is made - a private draft titled after the file, on the member's channel (created on their first upload) - or the replaced one found; `TranscodeVideo` is dispatched. Only a file inside the uploads directory is taken. A repeated call makes nothing twice. |

## Resuming

A cut connection retries by itself (tus `HEAD` gives the offset, `PATCH`
continues). A closed tab resumes when the same file is dropped again from
the same browser (Uppy keeps the upload's address). `video:uploads:clean`
removes what was abandoned (`--days=3`).
