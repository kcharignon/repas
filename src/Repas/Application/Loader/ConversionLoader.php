<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionsDTO;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;

interface ConversionLoader
{
    /**
     * Les conversions générales (communes à tous les ingrédients) et celles des ingrédients DU LECTEUR, triées par ingrédient (les
     * conversions générales à la fin) puis par unité de départ. Jamais celles des ingrédients d'un autre utilisateur, même pour un
     * administrateur (ADR 0028, amendement, D4).
     *
     * @return list<ConversionDTO>
     */
    public function list(string $viewerId): array;

    /**
     * @throws ConversionException la conversion est inconnue, ou propre à l'ingrédient d'un autre utilisateur
     */
    public function get(string $id, string $viewerId): ConversionDTO;

    /**
     * Les conversions d'UN ingrédient du lecteur, en une lecture : ses conversions propres, les universelles qui touchent ses unités (ses
     * deux unités par défaut et celles de ses conversions propres) et si son unité des recettes se convertit en son unité d'achat.
     *
     * @throws IngredientException l'ingrédient est inconnu, ou à un autre utilisateur (un administrateur compris)
     */
    public function listOfIngredient(string $slug, string $viewerId): IngredientConversionsDTO;
}
