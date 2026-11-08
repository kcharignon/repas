<?php

namespace Repas\Repas\Application\Command\UpdateRecipe;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\RecipeCopier;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateRecipeHandler
{
    public function __construct(
        private RecipeRepository $recipeRepository,
        private RecipeTypeRepository $recipeTypeRepository,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
        private ConversionService $conversionService,
        private IngredientSlugResolver $slugResolver,
        private RecipeCopier $copier,
    ) {
    }

    /**
     * @throws UnitException
     * @throws RecipeException
     * @throws IngredientException
     */
    public function __invoke(UpdateRecipeCommand $command): void
    {

        $recipe = $this->recipeRepository->findOneById($command->id);
        $recipe->assertVersion($command->expectedVersion);
        if ($command->byAdministrator) {
            // Une recette SANS PROPRIÉTAIRE (la neutre d'une recette Marmiton) se modifie par un administrateur, directement (ADR 0037,
            // amendement du 2026-11-08) ; sans effet sur une recette ordinaire. Un autre utilisateur la trouve figée (403).
            $recipe->allowAdministratorEdition();
        }

        // Le droit de changer le choix « recette secrète » se juge avant toute autre modification : absent, il reste ce qu'il était ; envoyé,
        // il ne change que par l'auteur (ADR 0033), et sinon rien n'est écrit
        if ($command->secret !== null) {
            $recipe->changeSecret($command->secret, $command->userId ?? '');
        }

        $type = $this->recipeTypeRepository->findOneBySlug($command->typeSlug);

        $recipe->update(
            // La première lettre du nom est mise en majuscule en silence, le reste est conservé (ADR 0030)
            name: StringTool::capitalizeFirst($command->name),
            type: $type,
            serving: $command->serving,
        );

        // Les couples (ingrédient, unité) que la recette a DÉJÀ : un couple qui ne se convertit pas (une ligne « à régler », par exemple dans
        // une recette copiée de Marmiton) y reste toléré, sans quoi une recette qui en porte une ne pourrait plus rien enregistrer, pas même
        // retirer cette ligne ou en changer une autre. Seul un couple NOUVEAU (autre ingrédient, autre unité) est contrôlé ; planifier la
        // recette reste refusé tant qu'une ligne n'est pas réglée (`convertible`).
        $alreadyThere = [];
        foreach ($recipe->getRows() as $existingRow) {
            $alreadyThere[$existingRow->getIngredient()->getSlug() . '|' . $existingRow->getUnit()->getSlug()] = true;
        }

        $lines = $recipe->isMarmitonNeutral()
            ? $this->linesOfARecipeWithoutOwner($command, $alreadyThere)
            : $this->linesOfTheAuthor($recipe, $command, $alreadyThere);

        $recipe->setRows(Tab::newEmptyTyped(RecipeRow::class));
        foreach ($lines as [$ingredient, $unit, $quantity]) {
            $recipe->addRow($ingredient, $unit, $quantity);
        }

        $this->recipeRepository->save($recipe);
    }

    /**
     * Les lignes d'une recette ordinaire : les slugs reçus se lisent pour l'AUTEUR de la recette (un administrateur peut la modifier), un
     * ancien slug est remplacé par l'ingrédient actuel de l'auteur (ADR 0028 décision 4).
     *
     * @param array<string, true> $alreadyThere les couples « ingrédient|unité » que la recette porte déjà
     * @return list<array{0: Ingredient, 1: Unit, 2: float}>
     */
    private function linesOfTheAuthor(Recipe $recipe, UpdateRecipeCommand $command, array $alreadyThere): array
    {
        $resolved = $this->slugResolver->resolve($recipe->getAuthorId(), $command->rows->map(fn(UpdateRecipeRowSubCommand $row) => $row->ingredientSlug)->values());
        $slugOf = static fn(UpdateRecipeRowSubCommand $row): string => $resolved[$row->ingredientSlug] ?? $row->ingredientSlug;

        // Ingrédients et unités des lignes : une lecture par type, pas une par ligne
        $ingredients = $this->ingredientRepository
            ->findBySlugs($command->rows->map($slugOf))
            ->indexBy(fn(Ingredient $ingredient) => $ingredient->getSlug());
        $units = $this->unitsOf($command);

        $lines = [];
        foreach ($command->rows as $row) {
            $ingredient = $ingredients[$slugOf($row)];
            $unit = $units[$row->unitSlug] ?? throw UnitException::notFound($row->unitSlug);
            if (!isset($alreadyThere[$ingredient->getSlug() . '|' . $unit->getSlug()])) {
                $this->conversionService->assertConvertibleToPurchaseUnit($ingredient, $unit);
            }
            $lines[] = [$ingredient, $unit, $row->quantity];
        }

        return $lines;
    }

    /**
     * Les lignes d'une recette SANS PROPRIÉTAIRE modifiée par un administrateur (ADR 0037, amendement du 2026-11-08, MAR-005). La recette ne
     * peut porter que des ingrédients du propriétaire réservé (`Recipe::addRow`), mais l'éditeur de l'administrateur lui propose les SIENS :
     *
     * - une ligne dont l'ingrédient est DÉJÀ un ingrédient du propriétaire réservé reste telle quelle (c'est ce que l'éditeur renvoie pour les
     *   lignes qu'on ne change pas) ; son couple (ingrédient, unité) n'est contrôlé que s'il est nouveau, comme pour toute recette ;
     * - tout autre slug est lu POUR L'ADMINISTRATEUR (ses alias, ses ingrédients : celui d'un autre compte est introuvable), son unité doit se
     *   convertir dans l'unité d'achat de SON ingrédient, puis il est traduit en ingrédient du propriétaire réservé par le mécanisme de la copie
     *   de recette (`RecipeCopier` : un réservé de même origine ou de même nom est réutilisé, sinon l'ingrédient est copié avec ses conversions
     *   propres et son origine ; jamais de doublon d'une modification à l'autre).
     *
     * Rien n'est annoncé (le propriétaire réservé n'est pas un compte : ni statistique ni événement).
     *
     * @param array<string, true> $alreadyThere les couples « ingrédient|unité » que la recette porte déjà
     * @return list<array{0: Ingredient, 1: Unit, 2: float}>
     */
    private function linesOfARecipeWithoutOwner(UpdateRecipeCommand $command, array $alreadyThere): array
    {
        $editorId = $command->userId ?? throw RecipeException::frozen();

        $resolved = $this->slugResolver->resolve($editorId, $command->rows->map(fn(UpdateRecipeRowSubCommand $row) => $row->ingredientSlug)->values());
        $slugOf = static fn(UpdateRecipeRowSubCommand $row): string => $resolved[$row->ingredientSlug] ?? $row->ingredientSlug;
        $ingredients = $this->ingredientRepository
            ->findBySlugs($command->rows->map($slugOf))
            ->indexBy(fn(Ingredient $ingredient) => $ingredient->getSlug());
        $units = $this->unitsOf($command);

        // Ce qui est déjà au propriétaire réservé reste ; le reste doit être à l'administrateur (celui d'un autre compte n'existe pas pour lui)
        /** @var array<string, Ingredient> $editors les ingrédients de l'administrateur à traduire, par slug */
        $editors = [];
        /** @var array<string, array<string, Unit>> $unitsOfEditors */
        $unitsOfEditors = [];
        foreach ($command->rows as $row) {
            $ingredient = $ingredients[$slugOf($row)];
            $unit = $units[$row->unitSlug] ?? throw UnitException::notFound($row->unitSlug);
            if ($ingredient->isOwnedBy(Recipe::MARMITON_OWNER_ID)) {
                if (!isset($alreadyThere[$ingredient->getSlug() . '|' . $unit->getSlug()])) {
                    $this->conversionService->assertConvertibleToPurchaseUnit($ingredient, $unit);
                }
                continue;
            }
            if (!$ingredient->isOwnedBy($editorId)) {
                throw IngredientException::notFound($ingredient->getSlug());
            }
            // Un ingrédient de l'administrateur : sa ligne se règle avec SES conversions, avant la moindre écriture
            $this->conversionService->assertConvertibleToPurchaseUnit($ingredient, $unit);
            $editors[$ingredient->getSlug()] = $ingredient;
            $unitsOfEditors[$ingredient->getSlug()][$unit->getSlug()] = $unit;
        }

        $translated = $editors === [] ? [] : $this->copier->retainIngredientsForMarmitonOwner($editors, $unitsOfEditors);

        $lines = [];
        foreach ($command->rows as $row) {
            $ingredient = $ingredients[$slugOf($row)];
            $lines[] = [$translated[$ingredient->getSlug()] ?? $ingredient, $units[$row->unitSlug], $row->quantity];
        }

        return $lines;
    }

    /**
     * @return array<string, Unit> les unités des lignes, par slug (une lecture)
     */
    private function unitsOf(UpdateRecipeCommand $command): array
    {
        return $this->unitRepository
            ->findBySlugs($command->rows->map(fn(UpdateRecipeRowSubCommand $row) => $row->unitSlug)->unique())
            ->indexBy(fn(Unit $unit) => $unit->getSlug());
    }
}
