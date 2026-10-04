---
title: Moderation and back office
order: 9
---

# Comments

A `VideoComment` is omnibase's `Comment` (states, one level of replies,
Akismet, `CommentType`, `CommentGuard`) with `moment` - where the player
stood - and `pinned`. `1:23` typed in a text seeks the player. A film's
author pins one, hides some, closes them (studio); the moderators approve,
mark as spam, trash (back office).

# Reports

"Signaler" under a film (and per comment) makes an omnibase `Complaint`
(`source: video`, `location: video:<id>` / `comment:<id>`, the reason in
its context): handled on omnibase/admin's screen for them.

# Take-downs

`Moderation::takeDown($video, $reason)` (the back office's "Retirer", a
reason is required): the film leaves every page, its authors are told why
(the DSA's statement of reasons) and still see it; `restore()` puts it
back, private.

# Roles and screens

| | |
|---|---|
| `VIDEO_VIEW` | a film reachable, or its owners', or the moderators' |
| `VIDEO_EDIT` | its owners (and its channel's), or `ROLE_ADMIN` |
| `VIDEO_MODERATE` | `ROLE_EDITOR` and up |

```php
// the dashboard
yield MenuItem::block('video_overview', 'La plateforme', 'fa-solid fa-tv')->setSize(4);
MenuItem::linkToCrud(\Base\Video\Entity\Video::class, 'Vidéos', 'fa-solid fa-film');
MenuItem::linkToCrud(\Base\Video\Entity\Channel::class, 'Chaînes', 'fa-solid fa-tv');
MenuItem::linkToCrud(\Base\Video\Entity\VideoComment::class, 'Commentaires', 'fa-solid fa-comments');
MenuItem::linkToCrud(\Base\Entity\User\Complaint::class, 'Signalements', 'fa-solid fa-hand');
```

The films and channels are written by `ROLE_ADMIN`; taking down, restoring
and the comments by `ROLE_EDITOR`. The tile: comments waiting, reports
open, conversions running or failed, views of thirty days.
