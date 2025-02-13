<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectIngredientType extends AbstractType
{
    public function __construct(
        private readonly IngredientRepository $ingredientRepository,
        private readonly Security $security
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $ingredientChoices = [];
        foreach ($this->ingredientRepository->findByOwner($user) as $ingredient) {
            $ingredientChoices[StringTool::upperCaseFirst($ingredient->getName())] = $ingredient->getSlug();
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
