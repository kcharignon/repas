<?php

namespace Repas\Repas\Infrastructure\Http\Form;


use Repas\Repas\Application\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Traversable;

class IngredientType extends AbstractType implements DataMapperInterface
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
        private readonly UnitRepository $unitRepository,
    ) {
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

    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        // TODO: Implement mapDataToForms() method.
    }

    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        $forms = iterator_to_array($forms);

        $viewData = new CreateIngredientCommand(
            name: $forms['name']->getData(),
            image: '',
            departmentSlug: $forms['department']->getData(),
            defaultCookingUnitSlug: $forms['defaultCookingUnit']->getData(),
            defaultPurchaseUnitSlug: $forms['defaultPurchaseUnit']->getData(),
        );
    }
}
