<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\ListAvailableIngredientsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\StringTool;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectIngredientType extends AbstractType
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly Security $security
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $user = $this->security->getUser();
        assert($user instanceof AuthenticatedUser);

        $ingredientChoices = [];
        /** @var list<IngredientOptionDTO> $ingredients */
        $ingredients = $this->queryBus->ask(new ListAvailableIngredientsQuery($user->getId()));
        foreach ($ingredients as $ingredient) {
            $ingredientChoices[StringTool::upperCaseFirst($ingredient->name)] = $ingredient->slug;
        }

        $resolver->setDefaults([
            'choices' => $ingredientChoices,
            'label' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
