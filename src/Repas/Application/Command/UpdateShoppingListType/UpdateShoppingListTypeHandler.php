<?php

namespace Repas\Repas\Application\Command\UpdateShoppingListType;

use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Remplace le nom d'un type de liste et le jeu COMPLET de ses rayons (ADR 0036 décisions 4 et 13). Un rayon que le type n'a plus est
 * détaché ; un rayon rattaché alors qu'un autre type du même utilisateur l'avait est déplacé. Rien n'est écrit en cas de refus.
 */
#[AsMessageHandler]
readonly class UpdateShoppingListTypeHandler
{
    public function __construct(
        private ShoppingListTypeRepository $listTypeRepository,
        private DepartmentRepository $departmentRepository,
    ) {
    }

    /**
     * @throws ShoppingListTypeException le type n'est pas à l'utilisateur (inconnu, un administrateur compris), le nom est invalide ou déjà pris
     * @throws DepartmentException un rayon est inconnu
     */
    public function __invoke(UpdateShoppingListTypeCommand $command): void
    {
        $listType = $this->listTypeRepository->findOneById($command->id);
        $listType->assertOwnedBy($command->userId);

        // Les rayons sont des données globales : l'un d'eux inconnu est un 404, même si le nom est déjà pris
        $slugs = array_values(array_unique($command->departmentSlugs));
        if ($this->departmentRepository->findBySlugs(new Tab($slugs, 'string'))->count() !== count($slugs)) {
            throw DepartmentException::notFound();
        }

        $previousName = $listType->getSluggedName();
        $listType->rename(StringTool::capitalizeFirst($command->name));
        // Le nom n'est jugé que s'il change une fois normalisé : changer les rayons d'un type n'est jamais refusé à cause de son propre nom
        if ($listType->getSluggedName() !== $previousName && $this->listTypeRepository->ownerHasTypeNamed($command->userId, $listType->getSluggedName(), $listType->getId())) {
            throw ShoppingListTypeException::nameAlreadyUsed();
        }

        $listType->replaceDepartments($slugs);
        $this->listTypeRepository->save($listType);
    }
}
