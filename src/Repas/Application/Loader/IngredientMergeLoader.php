<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetMergePreview\MergePreviewDTO;
use Repas\Repas\Application\Query\ListMergeCandidates\MergeCandidateDTO;
use Repas\Repas\Domain\Exception\IngredientException;

interface IngredientMergeLoader
{
    /**
     * Ce que la fusion de `$absorbedSlug` dans `$keptSlug` ferait pour cet utilisateur, sans rien écrire. Les deux ingrédients doivent
     * être à lui : l'ingrédient d'un autre est inconnu, pour un administrateur aussi. Le verdict est celui de `IngredientMergePlanner`,
     * le même que l'écriture.
     *
     * @throws IngredientException l'un des deux est inconnu ou à un autre utilisateur
     */
    public function preview(string $userId, string $absorbedSlug, string $keptSlug): MergePreviewDTO;

    /**
     * Les ingrédients de l'utilisateur (les siens) avec lesquels `$slug` peut être fusionné, 30 au plus : ceux du même
     * rayon d'abord, les plus ressemblants en tête, puis les autres rayons. `$search` ne garde que ceux dont le nom contient ce texte.
     *
     * @return list<MergeCandidateDTO>
     * @throws IngredientException `$slug` est inconnu ou à un autre utilisateur (404)
     */
    public function candidates(string $userId, string $slug, ?string $search = null): array;
}
