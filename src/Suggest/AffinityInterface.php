<?php

namespace Base\Video\Suggest;

use Base\Video\Entity\Video;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What else makes two films close, from another bundle: omnibase/broadcast
 * says "same artist" (as strong as the same channel) and "same episode"
 * (as strong as the same list). The calculator adds, for a pair, the weight
 * of each strength they share once: same channel and same artist is 3, not 6.
 */
#[AutoconfigureTag('video.affinity')]
interface AffinityInterface
{
    /**
     * @param list<Video> $videos
     *
     * @return array<int, array<string, float>> video id => group key ("artist:12") => weight (3.0)
     */
    public function groups(array $videos): array;
}
