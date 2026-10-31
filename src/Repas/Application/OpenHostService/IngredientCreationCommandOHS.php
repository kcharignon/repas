<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\CreatedIngredient;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Application\Interface\CommandBusInterface;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : créer un ingrédient à l'utilisateur qui l'a nommé sans qu'il existe (ADR 0029
 * décision 11, VOX-303), et lister les rayons et les unités qu'il peut citer pour le créer. La création réutilise `CreateIngredientHandler`
 * par le bus transactionnel, comme l'API : mêmes contrôles (rayon et unité du catalogue, ingrédient déjà possédé dans ce rayon : `409`), mêmes
 * événements, même statistique.
 *
 * L'ingrédient créé a UNE seule unité, pour l'achat comme pour la cuisine (aucun coefficient à demander à voix haute) ; il est à l'utilisateur,
 * comme tout ingrédient (ADR 0028).
 */
final readonly class IngredientCreationCommandOHS
{
    public function __construct(
        private DepartmentRepository $departments,
        private UnitRepository $units,
        private CommandBusInterface $commandBus,
    ) {
    }

    /**
     * Les rayons du catalogue, triés par slug.
     *
     * @return list<Department>
     */
    public function departments(): array
    {
        return array_values($this->departments->findAll()->toArray());
    }

    /**
     * Les unités du catalogue, triées par slug.
     *
     * @return list<Unit>
     */
    public function units(): array
    {
        return array_values($this->units->findAll()->toArray());
    }

    /**
     * @throws \Repas\Shared\Domain\Exception\DomainException le rayon ou l'unité n'existe pas, ou l'utilisateur a déjà cet ingrédient dans ce rayon (rien n'est écrit)
     */
    public function create(string $userId, string $name, string $departmentSlug, string $unitSlug): CreatedIngredient
    {
        // Le rayon inconnu est refusé (404) avant toute écriture ; son nom sert à la réponse
        $department = $this->departments->findOneBySlug($departmentSlug);

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

        return new CreatedIngredient($slug, $name, $department->getName());
    }
}
