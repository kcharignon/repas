<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\CreateIngredient\CreateIngredientCommand;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\DataMapperInterface;
use Traversable;

class CreateIngredientType extends AbstractIngredientType implements DataMapperInterface
{
    private User $creator;

    public function __construct(
        readonly Security $security,
    ) {
        $connectedUser = $security->getUser();
        assert($connectedUser instanceof User);
        $this->creator = $connectedUser;
    }

    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        // TODO: Implement mapDataToForms() method.
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        $forms = iterator_to_array($forms);

        // Un admin creer des ingredients communs (accessible a tous)
        $viewData = new CreateIngredientCommand(
            name: $forms['name']->getData(),
            image: '',
            departmentSlug: $forms['department']->getData(),
            defaultCookingUnitSlug: $forms['defaultCookingUnit']->getData(),
            defaultPurchaseUnitSlug: $forms['defaultPurchaseUnit']->getData(),
            ownerId: $this->creator->isAdmin() ? null : $this->creator->getId(),
            coefficient: $forms['coefficient']->getData(),
        );
    }
}
