<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

/**
 * Une copie d'ingrédient ne recopie JAMAIS `parent_slug` (ADR 0038 décision 5, SUB-002) : le slug du parent d'un original est celui d'un ingrédient
 * d'un AUTRE compte. Le lien se refait chez le copieur, par provenance, ou n'existe pas. Quatre chemins de copie, sur la vraie base : le modèle
 * (`Ingredient::copyFromOriginal`), la copie en masse du catalogue (`copyCatalogIngredients`, l'amorçage), la copie d'une recette (le bus) et la
 * copie d'une recette qui ne contient que la partie (aucun parent copié « pour compléter »). L'adoption unitaire et la copie en masse des communs
 * (`Ingredient::adopt`, `copyIngredients`) ont disparu avec la migration d'adoption (ING-014).
 */
class CopiedIngredientsNeverCarryAForeignParentTest extends DatabaseTestCase
{
    private DataPersister $persister;
    private string $authorId;
    private string $copierId;
    private Ingredient $egg;
    private Ingredient $yolk;
    private Ingredient $white;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new DataPersister(static::getContainer());
        $author = new UserBuilder()->build();
        $copier = new UserBuilder()->withId('copier-id')->withEmail('copier@example.com')->build();
        $this->persister->saveUser($author);
        $this->persister->saveUser($copier);
        $this->authorId = $author->getId();
        $this->copierId = $copier->getId();

        // L'auteur a découpé son œuf : le jaune (2 par œuf) et le blanc (1) en sont des parties
        $this->egg = new IngredientBuilder()->isEgg()->withCreator($author, refreshSlug: true)->build();
        $this->yolk = new IngredientBuilder()->isEgg()->withName("Jaune d'œuf")->withCreator($author, refreshSlug: true)->withParent($this->egg->getSlug(), 2.0)->build();
        $this->white = new IngredientBuilder()->isEgg()->withName("Blanc d'œuf")->withCreator($author, refreshSlug: true)->withParent($this->egg->getSlug(), 1.0)->build();
        foreach ([$this->egg, $this->yolk, $this->white] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }
    }

    public function testTheModelCopyOfAPartHasNoParent(): void
    {
        // When
        $copy = Ingredient::copyFromOriginal($this->yolk, $this->copierId, 'jaune-d-oeuf' . $this->copierId);

        // Then : ni parent ni ratio, la provenance seule voyage
        $this->assertNull($copy->getParentSlug());
        $this->assertNull($copy->getParentRatio());
        $this->assertFalse($copy->isPart());
        $this->assertSame($this->yolk->getSlug(), $copy->getAdoptedFrom());
        $this->assertSame($this->egg->getSlug(), $this->yolk->getParentSlug(), 'l\'original garde le sien');
    }

    public function testTheBulkCopyOfTheCatalogNeverCopiesTheParent(): void
    {
        // Given : le catalogue du propriétaire découpé (le propriétaire est l'auteur ici)
        $this->entityManager->clear();
        $bulk = static::getContainer()->get(IngredientAdoptionBulkRepository::class);
        $copies = [$this->egg->getSlug() => 'oeuf-copie', $this->yolk->getSlug() => 'jaune-copie', $this->white->getSlug() => 'blanc-copie'];

        // When : la copie en masse de l'amorçage, sans la liaison
        $bulk->copyCatalogIngredients($this->copierId, $this->authorId, $copies);

        // Then : trois copies, aucune n'a de parent ni de ratio
        $connection = $this->entityManager->getConnection();
        $this->assertSame(3, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$this->copierId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND (parent_slug IS NOT NULL OR parent_ratio IS NOT NULL)', [$this->copierId]));
        $this->assertSame($this->egg->getSlug(), $connection->fetchOne('SELECT parent_slug FROM ingredient WHERE slug = ?', [$this->yolk->getSlug()]), 'le catalogue est intact');
    }

    public function testACopiedRecipeLinksTheCopiedPartsToTheCopiersOwnCopyOfTheParentAndNeverToTheAuthors(): void
    {
        // Given : une recette de l'auteur avec l'œuf, le jaune et le blanc ; le copieur n'a rien
        $this->saveRecipe($this->egg, $this->yolk, $this->white);
        $this->entityManager->clear();

        // When
        $this->copy();

        // Then : trois copies, le jaune et le blanc sont des parties de la copie d'œuf du copieur, avec les ratios de l'auteur
        $connection = $this->entityManager->getConnection();
        $copyOf = fn(Ingredient $original): string => (string) $connection->fetchOne('SELECT slug FROM ingredient WHERE creator = ? AND adopted_from = ?', [$this->copierId, $original->getSlug()]);
        $eggCopy = $copyOf($this->egg);
        $this->assertNotSame($this->egg->getSlug(), $eggCopy);
        $this->assertSame([$eggCopy, 2.0], $this->parentOf($copyOf($this->yolk)));
        $this->assertSame([$eggCopy, 1.0], $this->parentOf($copyOf($this->white)));
        $this->assertSame([null, null], $this->parentOf($eggCopy));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND parent_slug IN (?, ?, ?)', [$this->copierId, $this->egg->getSlug(), $this->yolk->getSlug(), $this->white->getSlug()]), 'jamais le slug d\'un autre compte');
        // L'auteur n'a pas bougé
        $this->assertSame([$this->egg->getSlug(), 2.0], $this->parentOf($this->yolk->getSlug()));
    }

    public function testACopiedRecipeThatHasOnlyThePartCopiesNoParentToCompleteIt(): void
    {
        // Given : la recette de l'auteur n'utilise que le jaune d'œuf
        $this->saveRecipe($this->yolk);
        $this->entityManager->clear();

        // When
        $this->copy();

        // Then : une seule copie, ordinaire (le copieur n'a pas de parent à qui la relier) ; l'œuf n'est pas copié
        $connection = $this->entityManager->getConnection();
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$this->copierId]));
        $copy = (string) $connection->fetchOne('SELECT slug FROM ingredient WHERE creator = ?', [$this->copierId]);
        $this->assertSame([null, null], $this->parentOf($copy));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from = ?', [$this->copierId, $this->egg->getSlug()]));
    }

    public function testACopiedRecipeNeverOverwritesALinkTheCopierAlreadyMade(): void
    {
        // Given : le copieur a déjà SA copie du jaune (reçue de l'auteur), qu'il a lui-même découpée comme partie d'un « Œuf extra » à lui, ratio 3
        $this->saveRecipe($this->egg, $this->yolk, $this->white);
        $connection = $this->entityManager->getConnection();
        $extra = 'oeuf-extra' . $this->copierId;
        $theirYolk = 'jaune-d-oeuf' . $this->copierId;
        foreach ([[$extra, 'Œuf extra', null, null, null], [$theirYolk, "Jaune d'œuf", $this->yolk->getSlug(), $extra, 3.0]] as [$slug, $name, $from, $parent, $ratio]) {
            $connection->insert('ingredient', [
                'slug' => $slug, 'name' => $name, 'image' => '', 'slugged_name' => $slug, 'department' => $this->egg->getDepartment()->getSlug(), 'default_cooking_unit' => 'unite',
                'default_purchase_unit' => 'unite', 'creator' => $this->copierId, 'compatible_units' => '["unite"]', 'adopted_from' => $from, 'is_base' => 0,
                'parent_slug' => $parent, 'parent_ratio' => $ratio,
            ]);
        }
        $connection->insert('ingredient_alias', ['user_id' => $this->copierId, 'alias_slug' => $this->yolk->getSlug(), 'ingredient_slug' => $theirYolk, 'kind' => 'adoption', 'source' => 'activation', 'created_at' => '2026-11-08 10:00:00']);
        $this->entityManager->clear();

        // When
        $this->copy();

        // Then : son lien est intact ; le blanc, copié, est relié à la copie d'œuf qu'il vient de recevoir
        $this->assertSame([$extra, 3.0], $this->parentOf($theirYolk));
        $whiteCopy = (string) $connection->fetchOne('SELECT slug FROM ingredient WHERE creator = ? AND adopted_from = ?', [$this->copierId, $this->white->getSlug()]);
        $eggCopy = (string) $connection->fetchOne('SELECT slug FROM ingredient WHERE creator = ? AND adopted_from = ?', [$this->copierId, $this->egg->getSlug()]);
        $this->assertSame([$eggCopy, 1.0], $this->parentOf($whiteCopy));
    }

    private function saveRecipe(Ingredient ...$ingredients): void
    {
        $builder = new RecipeBuilder()->withId('recipe-id')->withAuthorId($this->authorId);
        foreach ($ingredients as $ingredient) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isUnite())->withQuantity(2));
        }
        $this->persister->saveRecipe($builder->build());
    }

    private function copy(): void
    {
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new CopyRecipeCommand('recipe-id', $this->copierId, 'copy-id'));
        $this->entityManager->clear();
    }

    /**
     * @return array{0: ?string, 1: ?float}
     */
    private function parentOf(string $slug): array
    {
        $row = $this->entityManager->getConnection()->fetchNumeric('SELECT parent_slug, parent_ratio FROM ingredient WHERE slug = ?', [$slug]);
        $this->assertNotFalse($row, $slug);

        return [$row[0], $row[1] === null ? null : (float) $row[1]];
    }
}
