<?php

namespace Repas\Voice\Domain\Model;

use Repas\Voice\Domain\Service\SpokenUnits;

/**
 * Ce que la liste a fait d'un article retiré à voix haute, de quoi choisir les mots de la réponse (`VoiceReplies`, ADR 0029 décision 12) : la
 * quantité retirée et celle qui reste dans la ligne, dans l'unité de la ligne, si l'utilisateur a cité une unité et si cette unité n'est pas celle
 * de la ligne (inconnue de l'ingrédient, ou convertie dans l'unité de la ligne). Une quantité restante nulle : la ligne a disparu.
 */
final readonly class RemovedLine
{
    /**
     * Au-dessous de cette quantité, la ligne est épuisée : Alexa dit une quantité à deux décimales, « il t'en reste 0 litre » n'a pas de sens. Le même
     * seuil que `Repas\Domain\Model\ShoppingList::NEGLIGIBLE_QUANTITY`, qui décide de supprimer la ligne (un test les compare).
     */
    public const float NEGLIGIBLE_QUANTITY = 0.005;

    public function __construct(
        /** La quantité retirée, dans l'unité de la ligne. */
        public float $removed,
        /** La quantité qui reste dans la ligne, dans son unité ; 0 : la ligne a disparu. */
        public float $remaining,
        public string $unitSlug,
        public string $unitName,
        /** L'utilisateur a dit une unité (« deux kilos de tomates »), utilisée ou non. */
        public bool $unitCited = false,
        /** L'unité dite n'est pas celle de la ligne : inconnue de l'ingrédient, ou convertie. */
        public bool $unitSubstituted = false,
    ) {
    }

    public function isGone(): bool
    {
        return $this->remaining < self::NEGLIGIBLE_QUANTITY;
    }

    /** L'unité de la ligne est l'unité « unité » : elle ne se dit pas. */
    public function isCount(): bool
    {
        return $this->unitSlug === SpokenUnits::COUNT;
    }
}
