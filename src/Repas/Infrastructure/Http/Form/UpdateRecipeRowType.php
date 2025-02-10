<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UpdateRecipeRowType extends AbstractType implements DataTransformerInterface
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
            ->addModelTransformer($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function transform($value): array
    {
        if (!$value instanceof RecipeRow) {
            return [
                'ingredientSlug' => '',
                'unitSlug' => '',
                'quantity' => 0,
            ];
        }

        return [
            'ingredientSlug' => $value->getIngredient()->getSlug(),
            'unitSlug' => $value->getUnit()->getSlug(),
            'quantity' => $value->getQuantity(),
        ];
    }

    public function reverseTransform($value): UpdateRecipeRowSubCommand
    {
        if (!is_array($value)) {
            throw new TransformationFailedException("Expected an array.");
        }

        return new UpdateRecipeRowSubCommand(
            ingredientSlug: $value['ingredientSlug'] ?? '',
            unitSlug: $value['unitSlug'] ?? '',
            quantity: $value['quantity'] ?? 0
        );
    }
}
