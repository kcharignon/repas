<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Loader\UnitLoader;
use Repas\Shared\Application\Dto\CreatedIngredient;
use Repas\Shared\Application\Dto\SpokenChoice;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\SpokenIngredientCreator;

/**
 * Adaptateur du port `SpokenIngredientCreator` (ADR 0029 décision 11, VOX-303). La création réutilise `CreateIngredientHandler` par le bus
 * transactionnel, comme l'API : mêmes contrôles (rayon et unité du catalogue, ingrédient déjà possédé : `409`), même événement, même
 * statistique. Une seule unité pour l'achat et la cuisine (aucun coefficient à demander à voix haute) ; `image` vide, comme l'API.
 */
final readonly class SpokenIngredientCreatorAdapter implements SpokenIngredientCreator
{
    public function __construct(
        private DepartmentLoader $departmentLoader,
        private UnitLoader $unitLoader,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function departments(): array
    {
        return array_map(
            static fn($department): SpokenChoice => new SpokenChoice($department->slug, $department->name),
            $this->departmentLoader->list(),
        );
    }

    public function units(): array
    {
        return array_map(
            static fn($unit): SpokenChoice => new SpokenChoice($unit->slug, $unit->name),
            $this->unitLoader->list(),
        );
    }

    public function create(string $userId, string $name, string $departmentSlug, string $unitSlug): CreatedIngredient
    {
        // Le rayon inconnu est refusé (404) avant toute écriture ; son nom sert à la réponse
        $department = $this->departmentLoader->get($departmentSlug);

        $slug = $this->commandBus->dispatch(new CreateIngredientCommand(
            name: $name,
            image: '',
            departmentSlug: $departmentSlug,
            defaultCookingUnitSlug: $unitSlug,
            defaultPurchaseUnitSlug: $unitSlug,
            userId: $userId,
            coefficient: null,
        ));
        if (!is_string($slug)) {
            throw new \LogicException('CreateIngredientHandler doit renvoyer le slug de l\'ingrédient créé.');
        }

        return new CreatedIngredient($slug, $name, $department->name);
    }
}
