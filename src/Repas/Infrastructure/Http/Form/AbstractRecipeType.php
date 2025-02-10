<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class AbstractRecipeType extends AbstractType implements DataMapperInterface
{
    protected User $user;

    public function __construct(
        private readonly RecipeTypeRepository $recipeTypeRepository,
        private readonly Security $security,
    ) {
        $user = $this->security->getUser();
        assert($user instanceof User);
        $this->user = $user;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $typeChoices = [];
        foreach ($this->recipeTypeRepository->findAll() as $recipeType) {
            $typeChoices[StringTool::upperCaseFirst($recipeType->getName())] = $recipeType->getSlug();
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
                'entry_type' => $this->getRecipeRowTypeClass(),
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__name__',
            ])
            ->add('save', SubmitType::class, [
                'label' => $this->getButtonSaveLabel(),
            ])
            ->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    abstract protected function getRecipeRowTypeClass(): string;

    abstract protected function getButtonSaveLabel(): string;
}
