<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;

use Repas\Shared\Application\Interface\AddedItem;
use Repas\Voice\Domain\Model\AddedLine;

/**
 * Traduit ce que le port `VoiceShoppingList` a fait (`AddedItem`, de `Shared`) en ce dont `VoiceReplies` a besoin pour parler (`AddedLine`, du
 * domaine de `Voice`, qui n'importe rien de `Shared\Application`).
 */
final readonly class AddedLineFactory
{
    /**
     * @param bool $unitCited l'utilisateur a dit une unité (« deux kilos de tomates »)
     */
    public static function from(AddedItem $added, bool $unitCited): AddedLine
    {
        return new AddedLine(
            added: $added->addedQuantity,
            total: $added->quantity,
            unitSlug: $added->unitSlug,
            unitName: $added->unitName,
            existed: $added->lineExisted,
            unitCited: $unitCited,
            unitSubstituted: $added->unitSubstituted,
        );
    }
}
