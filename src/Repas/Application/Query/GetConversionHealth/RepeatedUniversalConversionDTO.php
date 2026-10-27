<?php

namespace Repas\Repas\Application\Query\GetConversionHealth;

/**
 * Des conversions PROPRES à un ingrédient qui répètent une paire d'unités déjà universelle (dans un sens ou dans l'autre), regroupées par paire et
 * coefficient : sans ingrédient, sans compte. Celles qui portent l'identifiant d'une ligne de référence sont des lignes universelles détournées (l'ancien
 * `PUT /api/v1/ingredients/{slug}` les rattachait à l'ingrédient qu'il enregistrait).
 */
final readonly class RepeatedUniversalConversionDTO
{
    /**
     * @param string $label « début > fin x coefficient »
     * @param int $count le nombre de conversions propres de cette paire et de ce coefficient
     * @param int $hijacked dont celles qui portent l'identifiant d'une ligne de référence
     */
    public function __construct(
        public string $label,
        public int $count,
        public int $hijacked,
    ) {
    }
}
