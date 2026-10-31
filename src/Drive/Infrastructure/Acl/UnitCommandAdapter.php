<?php

namespace Repas\Drive\Infrastructure\Acl;

use Repas\Drive\Application\Port\UnitCommandPort;
use Repas\Repas\Application\OpenHostService\UnitCommandOHS;

/**
 * Couche anticorruption (ADR 0035) : les unités que le contexte `Repas` publie pour qui écrit, telles quelles (des slugs de part et d'autre).
 */
final readonly class UnitCommandAdapter implements UnitCommandPort
{
    public function __construct(
        private UnitCommandOHS $units,
    ) {
    }

    public function existingSlugs(array $slugs): array
    {
        return $this->units->existingSlugs($slugs);
    }
}
