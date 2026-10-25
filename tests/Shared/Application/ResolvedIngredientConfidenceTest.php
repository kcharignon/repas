<?php

namespace Repas\Tests\Shared\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Shared\Application\Dto\ResolvedIngredient;

/**
 * Les trois confiances que `ResolvedIngredient` nomme sont celles que le classeur de `Repas` produit (`Confidence`) : le contexte `Voice`,
 * qui ne connaît pas `Repas`, compare la confiance à ces constantes pour savoir comment annoncer un choix.
 */
class ResolvedIngredientConfidenceTest extends TestCase
{
    public function testTheConstantsAreTheValuesOfTheRankersConfidence(): void
    {
        $this->assertSame(Confidence::SURE->value, ResolvedIngredient::SURE);
        $this->assertSame(Confidence::PROBABLE->value, ResolvedIngredient::PROBABLE);
        $this->assertSame(Confidence::UNSURE->value, ResolvedIngredient::UNSURE);
        $this->assertCount(3, Confidence::cases());
    }
}
