<?php

namespace Repas\Repas\Infrastructure\Http\Form;

use Repas\Repas\Application\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Form\FormInterface;
use Traversable;

class UpdateRecipeType extends AbstractRecipeType
{

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
            rows: $rows,
            typeSlug: $forms['typeSlug']->getData()
        );
    }

    protected function getRecipeRowTypeClass(): string
    {
        return UpdateRecipeRowType::class;
    }

    protected function getButtonSaveLabel(): string
    {
        return 'Modifier la recette';
    }
}
