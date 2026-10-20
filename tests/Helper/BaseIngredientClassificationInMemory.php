<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Domain\Interface\BaseIngredientClassification;

/**
 * Le classement « de base » à la main, pour les tests de handlers : `new BaseIngredientClassificationInMemory(['beurre' => true, 'piment' => false])`.
 */
final readonly class BaseIngredientClassificationInMemory implements BaseIngredientClassification
{
    /**
     * @param array<string, bool> $classification
     */
    public function __construct(private array $classification)
    {
    }

    public function all(): array
    {
        return $this->classification;
    }
}
