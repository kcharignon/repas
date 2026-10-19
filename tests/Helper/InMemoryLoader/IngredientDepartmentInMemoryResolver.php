<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Shared\Application\Interface\IngredientDepartmentResolver;

/**
 * Double idiot : renvoie les rayons programmés tels quels (ni filtre sur les slugs demandés) et enregistre ses appels.
 */
class IngredientDepartmentInMemoryResolver implements IngredientDepartmentResolver
{
    /** @var list<list<string>> slugs reçus à chaque appel, dans l'ordre des appels */
    public array $departmentSlugsByIngredientCalls = [];

    /**
     * @param array<string, string> $departments slug du rayon de Repas par slug d'ingrédient
     */
    public function __construct(
        private readonly array $departments = [],
    ) {
    }

    public function departmentSlugsByIngredient(array $ingredientSlugs): array
    {
        $this->departmentSlugsByIngredientCalls[] = $ingredientSlugs;

        return $this->departments;
    }
}
