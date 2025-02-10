<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;

abstract class AbstractRecipeRowType extends AbstractType implements DataMapperInterface
{
    public function __construct(
        protected readonly UnitRepository $unitRepository,
        protected readonly IngredientRepository $ingredientRepository,
        protected readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $ingredientChoices = [];
        foreach ($this->ingredientRepository->findByOwner($user) as $ingredient) {
            $ingredientChoices[ucfirst($ingredient->getName())] = $ingredient->getSlug();
        }

        $unitChoices = [];
        foreach ($this->unitRepository->findAll() as $unit) {
            $unitChoices[ucfirst($unit->getName())] = $unit->getSlug();
        }

        $builder
            ->add('ingredientSlug', ChoiceType::class, [
                'label' => false,
                'choices' => $ingredientChoices,
            ])
            ->add('unitSlug', ChoiceType::class, [
                'label' => false,
                'choices' => $unitChoices,
            ])
            ->add('quantity', NumberType::class, [
                'label' => false,
            ])
            ->setDataMapper($this);
    }
}
