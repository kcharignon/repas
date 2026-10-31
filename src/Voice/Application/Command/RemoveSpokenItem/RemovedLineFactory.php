<?php

namespace Repas\Voice\Application\Command\RemoveSpokenItem;

use Repas\Voice\Domain\Model\RemovedItem;
use Repas\Voice\Domain\Model\RemovedLine;

/**
 * Traduit ce que le port de la liste a fait (`RemovedItem`) en ce dont `VoiceReplies` a besoin pour parler (`RemovedLine`, qui ajoute si l'utilisateur a cité une unité).
 */
final readonly class RemovedLineFactory
{
    /**
     * @param bool $unitCited l'utilisateur a dit une unité (« deux kilos de tomates »)
     */
    public static function from(RemovedItem $removed, bool $unitCited): RemovedLine
    {
        return new RemovedLine(
            removed: $removed->removed,
            remaining: $removed->remaining,
            unitSlug: $removed->unitSlug,
            unitName: $removed->unitName,
            unitCited: $unitCited,
            unitSubstituted: $removed->unitSubstituted,
        );
    }
}
