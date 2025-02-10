<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
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

class UpdateRecipeType extends AbstractType implements DataMapperInterface
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
                'entry_type' => UpdateRecipeRowType::class,
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
        if (!$viewData instanceof Recipe) {
            return; // Rien à mapper si la donnée d'origine n'est pas valide
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['name']->setData($viewData->getName());
        $forms['serving']->setData($viewData->getServing());
        $forms['typeSlug']->setData($viewData->getType()->getSlug());

        // Transformer le Tab<CreateRecipeRowSubCommand> en array
        $rowsData = [];
        foreach ($viewData->getRows() as $row) {
            $rowsData[] = $row;
        }

        $forms['rows']->setData($rowsData);
    }

    /**
     * @param Recipe &$viewData
     */
    public function mapFormsToData(Traversable $forms, &$viewData): void
    {

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $rows = Tab::newEmptyTyped(UpdateRecipeRowSubCommand::class);

        foreach ($forms['rows']->getData() as $rowData) {
            if ($rowData instanceof UpdateRecipeRowSubCommand) {
                $rows[] = $rowData;
            }
        }
        // Mise à jour du viewData (CreateRecipeCommand)
        $viewData = new UpdateRecipeCommand(
            id: $viewData->getId(),
            name: $forms['name']->getData(),
            serving: $forms['serving']->getData(),
            authorId: $viewData->getAuthor()->getId(),
            rows: $rows,
            typeSlug: $forms['typeSlug']->getData()
        );
    }
}
