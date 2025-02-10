<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Traversable;

class RecipeType extends AbstractType implements DataMapperInterface
{
    public function __construct(
        private readonly RecipeTypeRepository $recipeTypeRepository,
        private readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $typeChoices = [];
        foreach ($this->recipeTypeRepository->findAll() as $recipeType) {
            $typeChoices[ucfirst($recipeType->getName())] = $recipeType->getSlug();
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la recette',
            ])
            ->add('serving', IntegerType::class, [
                'label' => 'Nombre de personnes',
                'empty_data' => $user->getDefaultServing(),
            ])
            ->add('typeSlug', ChoiceType::class, [
                'label' => 'Type de recette',
                'choices' => $typeChoices,
            ])
            ->add('rows', CollectionType::class, [
                'entry_type' => RecipeRowType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__name__',
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Créer la recette',
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function mapDataToForms($viewData, Traversable $forms): void
    {
        if (!$viewData instanceof CreateRecipeCommand) {
            return; // Rien à mapper si la donnée d'origine n'est pas valide
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->name);
        $forms['serving']->setData($viewData->serving);
        $forms['typeSlug']->setData($viewData->typeSlug);

        // Transformer le Tab<CreateRecipeRowSubCommand> en array
        $rowsData = [];
        foreach ($viewData->rows as $row) {
            $rowsData[] = $row;
        }

        $forms['rows']->setData($rowsData);
    }

    public function mapFormsToData(Traversable $forms, &$viewData): void
    {
        if (!$viewData instanceof CreateRecipeCommand) {
            throw new UnexpectedTypeException($viewData, CreateRecipeCommand::class);
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $rows = Tab::newEmptyTyped(CreateRecipeRowSubCommand::class);

        foreach ($forms['rows']->getData() as $rowData) {
            if ($rowData instanceof CreateRecipeRowSubCommand) {
                $rows[] = $rowData;
            }
        }

        dump($forms, $forms['typeSlug']->getData());
        // Mise à jour du viewData (CreateRecipeCommand)
        $viewData = new CreateRecipeCommand(
            id: $viewData->id,
            name: $forms['name']->getData(),
            serving: $forms['serving']->getData(),
            authorId: $viewData->authorId,
            rows: $rows,
            typeSlug: $forms['typeSlug']->getData()
        );

        dump($viewData);
    }
}
