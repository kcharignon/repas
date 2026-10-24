<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Domain\Exception\ConversionException;

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
}
