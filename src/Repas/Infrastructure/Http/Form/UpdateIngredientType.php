<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Traversable;

class UpdateIngredientType extends AbstractType implements DataMapperInterface
{
    private User $creator;

    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
        private readonly UnitRepository $unitRepository,
        readonly Security $security,
    ) {
        $connectedUser = $security->getUser();
        assert($connectedUser instanceof User);
        $this->creator = $connectedUser;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $unitChoices = [];
        foreach ($this->unitRepository->findAll() as $unit) {
            $unitChoices[ucfirst($unit->getName())] = $unit->getSlug();
        }

        $departmentChoices = [];
        foreach ($this->departmentRepository->findAll() as $department) {
            $departmentChoices[ucfirst($department->getName())] = $department->getId();
        }

        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'label' => 'Nom',
            ])
            ->add('department', ChoiceType::class, [
                'required' => true,
                'choices' => $departmentChoices,
            ])
            ->add('defaultCookingUnit', ChoiceType::class, [
                'required' => true,
                'choices' => $unitChoices,
            ])
            ->add('defaultPurchaseUnit', ChoiceType::class, [
                'required' => true,
                'choices' => $unitChoices,
            ])
            ->setDataMapper($this)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'empty_data' => null,
        ]);
    }

    /**
     * @param mixed $viewData
     * @param Traversable $forms
     * @return void
     */
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        if (!$viewData instanceof Ingredient) {
            return;
        }

        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->getName());
        $forms['department']->setData($viewData->getDepartment()->getSlug());
        $forms['defaultCookingUnit']->setData($viewData->getDefaultCookingUnit()->getSlug());
        $forms['defaultPurchaseUnit']->setData($viewData->getDefaultPurchaseUnit()->getSlug());
    }

    /**
     * @param UpdateIngredientCommand $viewData
     */
    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        if (!$viewData instanceof Ingredient) {
            return;
        }

        $forms = iterator_to_array($forms);

        $viewData = new UpdateIngredientCommand(
            slug: $viewData->getSlug(),
            name: $forms['name']->getData(),
            image: '',
            departmentSlug: $forms['department']->getData(),
            defaultCookingUnitSlug: $forms['defaultCookingUnit']->getData(),
            defaultPurchaseUnitSlug: $forms['defaultPurchaseUnit']->getData(),
        );
    }
}
