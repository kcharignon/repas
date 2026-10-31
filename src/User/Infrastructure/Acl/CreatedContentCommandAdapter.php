<?php

namespace Repas\User\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\CreatedContentCommandOHS;
use Repas\User\Application\Port\CreatedContentCommandPort;

/**
 * Couche anticorruption (ADR 0035) : les nombres que publie `Repas`, tels que le recomptage des statistiques les attend.
 */
final readonly class CreatedContentCommandAdapter implements CreatedContentCommandPort
{
    public function __construct(
        private CreatedContentCommandOHS $content,
    ) {
    }

    public function createdIngredients(string $userId): int
    {
        return $this->content->createdIngredients($userId);
    }

    public function recipes(string $userId): int
    {
        return $this->content->recipes($userId);
    }
}
