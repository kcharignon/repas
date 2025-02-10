<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Traversable;

class CreateRecipeType extends AbstractRecipeType
{

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    public function mapDataToForms($viewData, Traversable $forms): void
    {
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $forms['serving']->setData($this->user->getDefaultServing());
    }

    public function mapFormsToData(Traversable $forms, &$viewData): void
    {
        dump($viewData);
        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        $rows = Tab::newEmptyTyped(CreateRecipeRowSubCommand::class);

        dump($forms);
        dump($forms['rows']->getData());
        foreach ($forms['rows']->getData() as $rowData) {
            if ($rowData instanceof CreateRecipeRowSubCommand) {
                $rows[] = $rowData;
            }
        }
        // Mise à jour du viewData (CreateRecipeCommand)
        $viewData = new CreateRecipeCommand(
            id: UuidGenerator::new(),
            name: $forms['name']->getData(),
            serving: $forms['serving']->getData(),
            authorId: $this->user->getId(),
            rows: $rows,
            typeSlug: $forms['typeSlug']->getData()
        );
    }

    protected function getRecipeRowTypeClass(): string
    {
        return CreateRecipeRowType::class;
    }
}
