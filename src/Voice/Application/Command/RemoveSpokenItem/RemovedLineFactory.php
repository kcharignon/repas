<?php

namespace Repas\Voice\Application\Command\RemoveSpokenItem;

use Repas\Shared\Application\Interface\RemovedItem;
use Repas\Voice\Domain\Model\RemovedLine;

/**
 * Traduit ce que le port `VoiceShoppingList` a fait (`RemovedItem`, de `Shared`) en ce dont `VoiceReplies` a besoin pour parler (`RemovedLine`, du
 * domaine de `Voice`, qui n'importe rien de `Shared\Application`).
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
