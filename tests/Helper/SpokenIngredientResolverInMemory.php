<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;

/**
 * Double idiot du port `SpokenIngredientResolver` : renvoie ce qu'on lui a programmé pour une phrase (rien pour les autres) et note ses
 * appels. Il ne classe rien : le choix de l'ingrédient se prouve dans les tests du classeur et de l'adaptateur de `Repas`.
 */
final class SpokenIngredientResolverInMemory implements SpokenIngredientResolver
{
    /** @var array<string, ResolvedIngredient> */
    private array $answers = [];

    /** @var list<array{string, string}> les appels `[userId, phrase]`, dans l'ordre */
    public array $asked = [];

    public function willResolve(string $phrase, ResolvedIngredient $ingredient): self
    {
        $this->answers[$phrase] = $ingredient;

        return $this;
    }

    public function resolve(string $userId, string $phrase): ?ResolvedIngredient
    {
        $this->asked[] = [$userId, $phrase];

        return $this->answers[$phrase] ?? null;
    }
}
