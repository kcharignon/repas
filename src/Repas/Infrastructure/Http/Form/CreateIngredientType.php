<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\DataMapperInterface;
use Traversable;

class CreateIngredientType extends AbstractIngredientType implements DataMapperInterface
{
    private AuthenticatedUser $user;

    public function __construct(
        readonly Security $security,
    ) {
        $connectedUser = $security->getUser();
        assert($connectedUser instanceof AuthenticatedUser);
        $this->user = $connectedUser;
    }

    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        // TODO: Implement mapDataToForms() method.
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        $forms = iterator_to_array($forms);

        // Un administrateur crée des ingrédients communs, un utilisateur des ingrédients personnalisés : décidé par le handler
        $viewData = new CreateIngredientCommand(
            name: $forms['name']->getData(),
            image: '',
            departmentSlug: $forms['department']->getData(),
            defaultCookingUnitSlug: $forms['defaultCookingUnit']->getData(),
            defaultPurchaseUnitSlug: $forms['defaultPurchaseUnit']->getData(),
            userId: $this->user->getId(),
            coefficient: $forms['coefficient']->getData(),
        );
    }
}
