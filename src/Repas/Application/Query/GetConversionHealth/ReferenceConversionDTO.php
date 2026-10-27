<?php

namespace Repas\Repas\Application\Query\GetConversionHealth;

/**
 * Une conversion universelle de référence et son état en base : `PRESENT` (une ligne universelle relie la paire, dans un sens ou dans l'autre, avec
 * le coefficient attendu), `OTHER_COEFFICIENT` (elle relie la paire mais dit autre chose : un choix de l'administrateur, jamais corrigé tout seul) ou
 * `MISSING` (aucune ligne universelle ne la relie).
 */
final readonly class ReferenceConversionDTO
{
    public const string PRESENT = 'PRESENT';
    public const string OTHER_COEFFICIENT = 'OTHER_COEFFICIENT';
    public const string MISSING = 'MISSING';

    /**
     * @param string $label « début > fin x coefficient » attendu
     * @param ?string $found ce que dit la base (« début > fin x coefficient » tel qu'écrit), `null` quand la paire manque
     */
    public function __construct(
        public string $label,
        public string $state,
        public ?string $found = null,
    ) {
    }
}
