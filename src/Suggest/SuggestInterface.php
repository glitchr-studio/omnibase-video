<?php

namespace Base\Video\Suggest;

use Base\Entity\User;
use Base\Video\Entity\Video;

/**
 * What to watch next. Two implementations: LocalSuggest (always there: the
 * Related table computed at night, the member's history, the trends) and
 * GorseSuggest (gorse-in-one, optional). Suggestions picks the configured
 * one and falls back to the local one: Gorse off or down, nothing breaks.
 */
interface SuggestInterface
{
    /** A gesture: read (playback started), watch (a view counted), like, or "not interested" (negative). */
    public function feedback(string $type, User $user, Video $video): void;

    /** @return list<Video> for a member, from what they watched and liked */
    public function recommend(User $user, int $limit = 24): array;

    /** @param list<int> $videoIds what a visitor watched, the last first @return list<Video> */
    public function session(array $videoIds, int $limit = 24): array;

    /** @return list<Video> "à suivre" after this one */
    public function similar(Video $video, int $limit = 12): array;
}
