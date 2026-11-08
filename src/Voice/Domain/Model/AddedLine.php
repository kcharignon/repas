<?php

namespace Repas\Voice\Domain\Model;

use Repas\Voice\Domain\Service\SpokenUnits;

/**
 * Ce que la liste a retenu d'un article dit à voix haute, de quoi choisir les mots de la réponse (`VoiceReplies`, ADR 0029 décision 5) : la
 * quantité ajoutée et la quantité TOTALE de la ligne après l'ajout, dans l'unité de la ligne, si la ligne existait déjà, si l'utilisateur a cité
 * une unité et si cette unité n'est pas celle de la ligne (inconnue de l'ingrédient, ou convertie dans l'unité de la ligne).
 *
 * Une PARTIE d'ingrédient (« jaune d'œuf », ADR 0038 décision 33) s'est pliée dans la ligne de son parent : `$foldedInto` dit le nom du parent, et `$total`,
 * `$unitSlug` et `$unitName` décrivent alors la ligne du PARENT (« 3 œufs »), `$added` ce qui a été ajouté à la partie.
 *
 * `single()` est le défaut des réponses sans quantité : une unité d'achat, rien de cité.
 */
final readonly class AddedLine
{
    public function __construct(
        /** La quantité ajoutée, dans l'unité de la ligne. */
        public float $added,
        /** La quantité de la ligne après l'ajout, dans l'unité de la ligne. */
        public float $total,
        public string $unitSlug,
        public string $unitName,
        /** La ligne de cet ingrédient était déjà dans la liste. */
        public bool $existed = false,
        /** L'utilisateur a dit une unité (« deux kilos de tomates »), utilisée ou non. */
        public bool $unitCited = false,
        /** L'unité dite n'est pas celle de la ligne : inconnue de l'ingrédient, convertie, ou la ligne en avait déjà une autre. */
        public bool $unitSubstituted = false,
        /** Le nom de la liste d'un TYPE qui a reçu l'article (ADR 0036, LST-008) ; `null` : la liste principale. */
        public ?string $listName = null,
        /** Le nom du parent quand l'article dit est une partie d'ingrédient qui s'est pliée dans sa ligne ; `null` : un article ordinaire. */
        public ?string $foldedInto = null,
    ) {
    }

    /** Une unité d'achat, rien de dit : « du pain ». */
    public static function single(): self
    {
        return new self(1.0, 1.0, SpokenUnits::COUNT, 'unité');
    }

    /** L'unité de la ligne est l'unité « unité » : elle ne se dit pas (« 3 croquettes », pas « 3 unités de croquettes »). */
    public function isCount(): bool
    {
        return $this->unitSlug === SpokenUnits::COUNT;
    }
}
