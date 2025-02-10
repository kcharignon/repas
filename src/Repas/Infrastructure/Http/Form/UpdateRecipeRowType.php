<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UpdateRecipeRowType extends AbstractType implements DataMapperInterface
{
    public function __construct(
        private readonly UnitRepository $unitRepository,
        private readonly IngredientRepository $ingredientRepository,
        private readonly Security $security,
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        if (!$viewData instanceof RecipeRow) {
            return;
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['ingredientSlug']->setData($viewData->getIngredient()->getSlug());
        $forms['unitSlug']->setData($viewData->getUnit()->getSlug());
        $forms['quantity']->setData($viewData->getQuantity());
    }

    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $viewData = new UpdateRecipeRowSubCommand(
            ingredientSlug: $forms['ingredientSlug']->getData(),
            unitSlug: $forms['unitSlug']->getData(),
            quantity: $forms['quantity']->getData(),
        );
    }


}
