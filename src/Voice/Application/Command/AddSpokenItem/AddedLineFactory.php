<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;

use Repas\Voice\Domain\Model\AddedItem;
use Repas\Voice\Domain\Model\AddedLine;

/**
 * Traduit ce que le port de la liste a fait (`AddedItem`) en ce dont `VoiceReplies` a besoin pour parler (`AddedLine`, qui ajoute si l'utilisateur a cité une unité).
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
            listName: $added->listName,
        );
    }
}
