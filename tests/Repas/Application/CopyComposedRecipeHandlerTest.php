<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\RecipeCopier;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Repas\Domain\Service\RecipeComponentRules;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientMatchCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientPartLinkInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RecipeComponentLockSpy;

/**
 * Copier une recette COMPOSÉE copie aussi ses recettes utilisées (ADR 0040 décisions 33 à 35, COMP-003), par l'unique point d'entrée `RecipeCopier::copy()` :
 * les recettes utilisées d'abord, la recette composée ensuite avec des composants qui désignent les copies (multiplicateur et position gardés), UN seul
 * rapprochement d'ingrédients sur l'union de leurs lignes, une copie du copieur réutilisée quand elle peut servir, l'auteur qui copie sa propre recette
 * qui partage ses recettes utilisées, le plafond jugé sur « compte + recettes à créer », jamais une recette secrète d'un autre.
 *
 * L'auteur écrit « Tarte au citron » (4 personnes : de la farine et du sucre) qui utilise « Pâte sablée » (6 personnes : de la farine et du beurre) 2 fois et
 * « Crème citron » (4 personnes : du sucre et un citron) une demi-fois. Le copieur n'a rien : tout lui est copié.
 */
class CopyComposedRecipeHandlerTest extends TestCase
{
    private const string AUTHOR = 'author-id';
    private const string COPIER = 'copier-id';
    private const string THIRD = 'third-id';

    private CopyRecipeHandler $handler;
    private RecipeCopier $copier;
    private RecipeInMemoryRepository $recipes;
    private IngredientInMemoryRepository $ingredients;
    private IngredientPartLinkInMemoryRepository $partLinks;
    private RecipeComponentLockSpy $lock;
    private SpyEventDispatcher $events;

    private Ingredient $flour;
    private Ingredient $sugar;
    private Ingredient $butter;
    private Ingredient $lemon;

    protected function setUp(): void
    {
        $this->flour = $this->personal('farine', self::AUTHOR);
        $this->sugar = $this->personal('sucre', self::AUTHOR);
        $this->butter = $this->personal('beurre', self::AUTHOR);
        $this->lemon = $this->personal('citron', self::AUTHOR);
        $this->assemble();
    }

    /**
     * @param list<Recipe> $recipes
     * @param list<Ingredient> $moreIngredients
     */
    private function assemble(array $recipes = [], array $moreIngredients = []): void
    {
        $this->ingredients = new IngredientInMemoryRepository([$this->flour, $this->sugar, $this->butter, $this->lemon, ...$moreIngredients]);
        $conversions = new ConversionInMemoryRepository();
        $this->recipes = new RecipeInMemoryRepository($recipes);
        $this->partLinks = new IngredientPartLinkInMemoryRepository();
        $this->lock = new RecipeComponentLockSpy();
        $this->events = new SpyEventDispatcher();
        $units = new UnitInMemoryRepository([new UnitBuilder()->isUnite()->build(), new UnitBuilder()->isBox()->build()]);
        $this->copier = new RecipeCopier(
            $this->recipes,
            $this->ingredients,
            $conversions,
            new IngredientSlugResolver(new IngredientAliasInMemoryRepository()),
            new IngredientMatchCandidatesInMemoryRepository($this->ingredients),
            new IngredientNameMatcher(),
            new IngredientSlugPolicy(),
            new ConversionService($conversions, $units),
            $this->partLinks,
            $this->lock,
            new RecipeComponentRules(),
            new UuidGenerator(),
            $this->events,
        );
        $this->handler = new CopyRecipeHandler($this->recipes, $this->copier);
    }

    // --- L'arbre est copié, les recettes utilisées d'abord ---------------------------------------------------------------------------

    public function testAnotherAccountCopiesTheComposedRecipeAndItsUsedRecipesWhichAreCreatedFirst(): void
    {
        // Given
        $this->assemble($this->tartAndItsRecipes());

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : trois recettes de plus, à lui, ordinaires, au nom en majuscule et au lien d'origine, la recette composée en dernier
        $created = array_values(array_filter($this->recipes->findAll(), static fn(Recipe $recipe): bool => $recipe->getAuthorId() === self::COPIER));
        $this->assertCount(3, $created);
        $this->assertSame('copy-id', $created[2]->getId(), 'la recette composée est enregistrée après les recettes utilisées');
        [$doughCopy, $creamCopy, $tartCopy] = $created;
        $this->assertSame(['Pâte sablée', 'dough-id', false], [$doughCopy->getName(), $doughCopy->getOriginalId(), $doughCopy->isSecret()]);
        $this->assertSame(['Crème citron', 'cream-id', false], [$creamCopy->getName(), $creamCopy->getOriginalId(), $creamCopy->isSecret()]);
        $this->assertSame(['Tarte au citron', 'tart-id', false], [$tartCopy->getName(), $tartCopy->getOriginalId(), $tartCopy->isSecret()]);
        // La copie utilise les COPIES (multiplicateur et position gardés) et rien ne désigne les recettes de l'auteur
        $this->assertSame([[$doughCopy->getId(), 2.0, 0], [$creamCopy->getId(), 0.5, 1]], $this->edgesOf($tartCopy));
        $this->assertSame(['copy-id'], array_values(array_unique(array_map(static fn(RecipeComponent $edge): string => $edge->getRecipeId(), $tartCopy->getComponents()->toArray()))));
        $this->assertSame(2, $tartCopy->getComponentCount());
        $this->assertTrue($tartCopy->areComponentsChanged());
        // Les lignes sont celles de l'original, à ses ingrédients à lui
        $this->assertSame(['farine', 'sucre'], $this->slugsOf($tartCopy, self::COPIER));
        $this->assertSame(['farine', 'beurre'], $this->slugsOf($doughCopy, self::COPIER));
        $this->assertSame(['sucre', 'citron'], $this->slugsOf($creamCopy, self::COPIER));
        // Les copies utilisées ne sont pas composées ; les originaux n'ont pas bougé
        $this->assertSame([0, 0], [$doughCopy->getComponentCount(), $creamCopy->getComponentCount()]);
        $this->assertSame([['dough-id', 2.0, 0], ['cream-id', 0.5, 1]], $this->edgesOf($this->recipes->findOneById('tart-id')));
        $this->assertCount(3, $this->recipes->findByAuthor(self::AUTHOR));
    }

    public function testASingleEventCarriesEveryCreatedRecipeAndNoIngredient(): void
    {
        // Given
        $this->assemble($this->tartAndItsRecipes());

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : un événement pour le copieur, les deux recettes utilisées puis la recette composée
        $this->assertCount(1, $this->events->eventDispatched);
        $event = $this->events->eventDispatched[0];
        $this->assertInstanceOf(RecipesOrIngredientsCreatedEvent::class, $event);
        $this->assertSame(self::COPIER, $event->userId);
        $this->assertSame([], $event->ingredientSlugs->toArray());
        $ids = array_values($event->recipeIds->toArray());
        $this->assertCount(3, $ids);
        $this->assertSame('copy-id', $ids[2]);
        $this->assertEqualsCanonicalizing(
            array_map(static fn(Recipe $recipe): string => $recipe->getId(), $this->copiesOf(self::COPIER)),
            $ids,
        );
    }

    public function testAComposedRecipeWithoutAnyRowOfItsOwnIsNeverCopiedEmpty(): void
    {
        // Given : une recette faite seulement de recettes utilisées
        $dough = $this->dough();
        $cream = $this->cream();
        $only = new RecipeBuilder()->withId('only-id')->withName('Assemblage')->withAuthorId(self::AUTHOR)->withComponent($dough, 1.0)->withComponent($cream, 3.0)->build();
        $this->assemble([$dough->build(), $cream->build(), $only]);

        // When
        ($this->handler)(new CopyRecipeCommand('only-id', self::COPIER, 'copy-id'));

        // Then : la copie n'a aucune ligne mais deux composants, qui désignent les copies
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertCount(0, $copy->getRows());
        $this->assertSame(2, $copy->getComponentCount());
        $this->assertSame([1.0, 3.0], array_column($this->edgesOf($copy), 1));
        foreach ($copy->getComponents() as $edge) {
            $this->assertSame(self::COPIER, $this->recipes->findOneById($edge->getComponentId())->getAuthorId());
        }
    }

    // --- Un seul rapprochement d'ingrédients -----------------------------------------------------------------------------------------

    public function testAnIngredientOfTheRecipeAndOfItsUsedRecipesIsCopiedOnceAndSharedByAllTheCopies(): void
    {
        // Given : la farine est dans la tarte ET dans la pâte, le sucre dans la tarte ET dans la crème
        $this->assemble($this->tartAndItsRecipes());

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : quatre ingrédients au copieur (farine, sucre, beurre, citron), jamais un doublon, chacune des copies utilise le même
        $owned = array_map(static fn(Ingredient $ingredient): string => $ingredient->getSlug(), array_values($this->ingredients->findByOwner(self::COPIER)->toArray()));
        sort($owned);
        $this->assertSame([$this->copySlug('beurre'), $this->copySlug('citron'), $this->copySlug('farine'), $this->copySlug('sucre')], $owned);
        $copies = $this->copiesOf(self::COPIER);
        $flourSlugs = [];
        foreach ($copies as $copy) {
            foreach ($copy->getRows() as $row) {
                if (str_starts_with($row->getIngredient()->getSlug(), StringTool::slugify('farine'))) {
                    $flourSlugs[] = $row->getIngredient()->getSlug();
                }
            }
        }
        $this->assertSame([$this->copySlug('farine')], array_values(array_unique($flourSlugs)));
        $this->assertCount(2, $flourSlugs, 'la farine est sur une ligne de la tarte et une ligne de la pâte');
        // Ils retiennent l'origine, aucun n'est une création
        $this->assertSame('farine' . self::AUTHOR, $this->ingredients->findOneBySlug($this->copySlug('farine'))->getAdoptedFrom());
    }

    public function testTheUnitsOfEveryRecipeAreMergedBeforeAnIngredientOfTheCopierIsRetained(): void
    {
        // Given : le copieur a sa farine (même nom, même rayon), achetée en boîte. La tarte la compte en boîtes, la pâte à l'unité : sa farine ne sert
        // pas la pâte, donc elle ne sert pas la recette toute entière (un seul rapprochement sur l'union des unités)
        $copiersFlour = $this->personal('farine', self::COPIER, $this->unit()->isBox());
        $tart = $this->tart($this->dough(), $this->cream())->build();
        $dough = new RecipeBuilder()->withId('dough-id')->withName('pâte sablée')->withAuthorId(self::AUTHOR)->withServing(6)
            ->addRow($this->row($this->flour, $this->unit()->isUnite(), 250))->addRow($this->row($this->butter, $this->unit()->isUnite(), 125));
        $this->assemble([$tart, $dough->build(), $this->cream()->build()], [$copiersFlour]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : la farine de l'auteur est copiée UNE fois (un second ingrédient « Farine » au copieur), et la tarte comme la pâte utilisent cette copie :
        // aucune ne garde celle du copieur
        $tartCopy = $this->recipes->findOneById('copy-id');
        $doughCopy = $this->recipes->findOneById($this->componentIdsOf($tartCopy)[0]);
        $this->assertNotSame($copiersFlour->getSlug(), $this->rowSlug($tartCopy, 0));
        $this->assertSame($this->rowSlug($tartCopy, 0), $this->rowSlug($doughCopy, 0));
        $this->assertSame(StringTool::slugify('farine-2' . self::COPIER), $this->rowSlug($tartCopy, 0));
        $this->assertSame('farine' . self::AUTHOR, $this->ingredients->findOneBySlug($this->rowSlug($tartCopy, 0))->getAdoptedFrom());
        $this->assertCount(5, $this->ingredients->findByOwner(self::COPIER), 'sa farine, la copie de la farine, le sucre, le beurre et le citron');
    }

    public function testAPartOfTheAuthorUsedByTheRecipeAndItsUsedRecipeIsLinkedOnceAfterTheCopies(): void
    {
        // Given : le jaune d'œuf, partie de l'œuf, est dans la tarte ET dans la pâte
        $egg = $this->personal('oeuf', self::AUTHOR);
        $yolk = $this->builder('jaune', $this->unit()->isUnite())->withSlug('jaune' . self::AUTHOR)->withCreator(new UserBuilder()->withId(self::AUTHOR)->build())->withParent($egg->getSlug(), 1.0)->build();
        $dough = new RecipeBuilder()->withId('dough-id')->withName('pâte sablée')->withAuthorId(self::AUTHOR)->withServing(6)->addRow($this->row($yolk, $this->unit()->isUnite(), 2));
        $tart = new RecipeBuilder()->withId('tart-id')->withName('tarte au citron')->withAuthorId(self::AUTHOR)->withServing(4)->addRow($this->row($yolk, $this->unit()->isUnite(), 1))->withComponent($dough, 1.0);
        $this->assemble([$dough->build(), $tart->build()], [$egg, $yolk]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : une seule liaison pour tout le copieur, après les copies (jamais un parent copié « pour compléter »)
        $this->assertSame([self::COPIER], $this->partLinks->linkedUsers);
        $this->assertFalse($this->ingredients->exists('oeuf' . self::COPIER));
    }

    // --- Une copie du copieur est réutilisée quand elle peut servir ------------------------------------------------------------------

    public function testTheCopiersOwnCopyOfAUsedRecipeIsReusedInsteadOfCopyingItAgain(): void
    {
        // Given : le copieur a déjà copié la pâte (ordinaire, sans composants)
        $existing = new RecipeBuilder()->withId('my-dough')->withName('Ma pâte')->withAuthorId(self::COPIER)->withOriginalId('dough-id')
            ->addRow($this->row($this->personal('farine', self::COPIER), $this->unit()->isBox(), 1))->build();
        $this->assemble([...$this->tartAndItsRecipes(), $existing], [$this->personal('farine', self::COPIER)]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : la tarte utilise SA pâte (au multiplicateur de l'original) et la crème copiée ; seules la crème et la tarte sont créées
        $tartCopy = $this->recipes->findOneById('copy-id');
        $creamCopy = array_values(array_filter($this->copiesOf(self::COPIER), static fn(Recipe $recipe): bool => $recipe->getOriginalId() === 'cream-id'))[0];
        $this->assertSame([['my-dough', 2.0, 0], [$creamCopy->getId(), 0.5, 1]], $this->edgesOf($tartCopy));
        $this->assertCount(3, $this->copiesOf(self::COPIER), 'sa pâte, la crème copiée et la tarte copiée');
        $this->assertSame([$creamCopy->getId(), 'copy-id'], array_values($this->events->eventDispatched[0]->recipeIds->toArray()), 'une copie réutilisée n\'est pas une création');
        // Le beurre, que la pâte seule utilisait, n'est pas copié : le rapprochement ne porte que sur ce qui est copié
        $this->assertFalse($this->ingredients->exists($this->copySlug('beurre')));
        $this->assertSame('Ma pâte', $this->recipes->findOneById('my-dough')->getName(), 'sa copie n\'est pas modifiée');
    }

    public function testACopyOfTheCopierWhichHasComponentsIsNotReusedAndANewCopyIsMade(): void
    {
        // Given : sa copie de la pâte utilise une de ses recettes (profondeur 1 : elle ne peut plus être utilisée à son tour)
        $mine = new RecipeBuilder()->withId('my-other')->withName('Autre')->withAuthorId(self::COPIER)->addRow($this->row($this->personal('farine', self::COPIER), $this->unit()->isBox(), 1));
        $existing = new RecipeBuilder()->withId('my-dough')->withName('Ma pâte')->withAuthorId(self::COPIER)->withOriginalId('dough-id')->withComponent($mine, 1.0)
            ->addRow($this->row($this->personal('farine', self::COPIER), $this->unit()->isBox(), 1))->build();
        $this->assemble([...$this->tartAndItsRecipes(), $mine->build(), $existing], [$this->personal('farine', self::COPIER)]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : une copie neuve de la pâte, la sienne reste composée
        $doughCopies = array_values(array_filter($this->copiesOf(self::COPIER), static fn(Recipe $recipe): bool => $recipe->getOriginalId() === 'dough-id'));
        $this->assertCount(2, $doughCopies);
        $newDough = array_values(array_filter($doughCopies, static fn(Recipe $recipe): bool => $recipe->getId() !== 'my-dough'))[0];
        $this->assertSame($newDough->getId(), $this->recipes->findOneById('copy-id')->getComponents()->reset()->getComponentId());
        $this->assertSame(0, $newDough->getComponentCount());
    }

    public function testASecretCopyOfTheCopierIsNotReusedByAnOrdinaryCopy(): void
    {
        // Given : sa copie de la pâte est devenue secrète
        $existing = new RecipeBuilder()->withId('my-dough')->withName('Ma pâte')->withAuthorId(self::COPIER)->withOriginalId('dough-id')->asSecret()
            ->addRow($this->row($this->personal('farine', self::COPIER), $this->unit()->isBox(), 1))->build();
        $this->assemble([...$this->tartAndItsRecipes(), $existing], [$this->personal('farine', self::COPIER)]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : la tarte ordinaire n'utilise pas une recette secrète, elle reçoit une copie neuve, ordinaire
        $tartCopy = $this->recipes->findOneById('copy-id');
        $usedDough = $this->recipes->findOneById($tartCopy->getComponents()->reset()->getComponentId());
        $this->assertNotSame('my-dough', $usedDough->getId());
        $this->assertFalse($usedDough->isSecret());
        $this->assertFalse($tartCopy->isSecret());
        $this->assertTrue($this->recipes->findOneById('my-dough')->isSecret(), 'sa recette secrète n\'est pas rendue publique');
    }

    // --- L'auteur qui copie sa propre recette partage ses recettes utilisées ---------------------------------------------------------

    public function testTheAuthorCopyingHisOwnComposedRecipeSharesItsUsedRecipesAndCopiesNothingElse(): void
    {
        // Given
        $this->assemble($this->tartAndItsRecipes());

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::AUTHOR, 'copy-id'));

        // Then : une seule recette de plus, qui utilise les MÊMES recettes utilisées (mêmes identifiants, multiplicateurs et positions)
        $this->assertCount(4, $this->recipes->findByAuthor(self::AUTHOR));
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertSame([['dough-id', 2.0, 0], ['cream-id', 0.5, 1]], $this->edgesOf($copy));
        $this->assertSame(['tart-id', false], [$copy->getOriginalId(), $copy->isSecret()]);
        $this->assertSame(['copy-id'], array_values($this->events->eventDispatched[0]->recipeIds->toArray()));
        // Aucun ingrédient copié : ce sont déjà les siens
        $this->assertCount(0, $this->ingredients->findByOwner(self::COPIER));
        $this->assertSame(4, $this->ingredients->findByOwner(self::AUTHOR)->count());
    }

    public function testTheCopyOfASecretComposedRecipeByItsAuthorIsSecretAndMayUseSecretRecipes(): void
    {
        // Given : une composée secrète qui utilise une recette secrète
        $secretDough = $this->dough()->asSecret();
        $tart = $this->tart($secretDough, $this->cream())->asSecret()->build();
        $this->assemble([$secretDough->build(), $this->cream()->build(), $tart]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::AUTHOR, 'copy-id'));

        // Then : la copie est secrète et garde la recette secrète
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertTrue($copy->isSecret());
        $this->assertSame([['dough-id', 2.0, 0], ['cream-id', 0.5, 1]], $this->edgesOf($copy));
    }

    public function testAnOrdinaryCopyNeverKeepsASecretUsedRecipeEvenWhenTheStoredDataAsksForIt(): void
    {
        // Given : une donnée que l'écriture refuse (une recette ordinaire qui utilise une secrète), par le dépôt
        $secretDough = $this->dough()->asSecret();
        $tart = $this->tart($secretDough, $this->cream())->build();
        $this->assemble([$secretDough->build(), $this->cream()->build(), $tart]);

        // When : l'auteur la copie
        ($this->handler)(new CopyRecipeCommand('tart-id', self::AUTHOR, 'copy-id'));

        // Then : la copie ordinaire ne garde que la crème, à la position 0 : elle n'expose pas la recette secrète
        $this->assertSame([['cream-id', 0.5, 0]], $this->edgesOf($this->recipes->findOneById('copy-id')));
    }

    // --- Ce que le copieur ne peut pas voir ni copier --------------------------------------------------------------------------------

    public function testTheSecretComposedRecipeOfAnotherIsNotFoundAndNothingIsWrittenNorLocked(): void
    {
        // Given
        $tart = $this->tart($this->dough(), $this->cream())->asSecret()->build();
        $this->assemble([$tart, $this->dough()->build(), $this->cream()->build()]);

        // When
        try {
            ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));
            $this->fail('La recette secrète d\'un autre ne doit pas se copier.');
        } catch (RecipeException $exception) {
            // Then : le message d'une recette inconnue, avant toute lecture, tout verrou ou toute écriture
            $this->assertEquals(RecipeException::notFound('tart-id'), $exception);
        }
        $this->assertSame([], $this->lock->acquired);
        $this->assertCount(0, $this->recipes->findByAuthor(self::COPIER));
        $this->assertCount(0, $this->ingredients->findByOwner(self::COPIER));
        $this->assertSame([], $this->events->eventDispatched);
    }

    public function testASecretUsedRecipeOfAnotherIsNeverCopiedNorReferencedAndThePositionsStayWithoutHole(): void
    {
        // Given : une donnée que l'écriture refuse (une recette ordinaire qui utilise une secrète) ; la copie ne doit rien en montrer
        $secretDough = $this->dough()->asSecret();
        $tart = $this->tart($secretDough, $this->cream())->build();
        $this->assemble([$secretDough->build(), $this->cream()->build(), $tart]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : seule la crème est copiée et utilisée, à la position 0 ; la pâte secrète n'est ni copiée, ni désignée, ni son beurre copié
        $copies = $this->copiesOf(self::COPIER);
        $this->assertSame(['cream-id', 'tart-id'], array_map(static fn(Recipe $recipe): string => $recipe->getOriginalId(), $copies));
        $this->assertSame([[$copies[0]->getId(), 0.5, 0]], $this->edgesOf($this->recipes->findOneById('copy-id')));
        $this->assertFalse($this->ingredients->exists($this->copySlug('beurre')));
        $this->assertNotContains('dough-id', array_map(static fn(Recipe $recipe): ?string => $recipe->getOriginalId(), $this->recipes->findAll()));
    }

    public function testAUsedRecipeOfAnotherAuthorIsIgnored(): void
    {
        // Given : une arête vers la recette d'un troisième compte (l'écriture exige le même auteur)
        $foreign = new RecipeBuilder()->withId('foreign-id')->withName('Ailleurs')->withAuthorId(self::THIRD)->addRow($this->row($this->personal('farine', self::THIRD), $this->unit()->isBox(), 1));
        $tart = $this->tart($foreign, $this->cream())->build();
        $this->assemble([$foreign->build(), $this->cream()->build(), $tart], [$this->personal('farine', self::THIRD)]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : seule la crème est copiée, la recette d'un autre auteur n'est pas copiée
        $this->assertSame(['cream-id', 'tart-id'], array_map(static fn(Recipe $recipe): string => $recipe->getOriginalId(), $this->copiesOf(self::COPIER)));
        $this->assertSame(1, $this->recipes->findOneById('copy-id')->getComponentCount());
    }

    public function testAnOrphanEdgeIsIgnoredAndTheCopyKeepsTheOthersWithoutHoleInPositions(): void
    {
        // Given : l'arête de rang 0 désigne une recette qui n'existe plus
        $tart = new RecipeBuilder()->withId('tart-id')->withName('tarte au citron')->withAuthorId(self::AUTHOR)->withServing(4)
            ->addRow($this->row($this->flour, $this->unit()->isBox(), 2))
            ->withComponent(new RecipeBuilder()->withId('ghost-id'), 1.0)->withComponent($this->dough(), 3.0)->build();
        $this->assemble([$tart, $this->dough()->build()]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : un composant, la copie de la pâte, au rang 0, sans erreur
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertCount(1, $copy->getComponents());
        $edge = $copy->getComponents()->reset();
        $this->assertSame([3.0, 0], [$edge->getMultiplier(), $edge->getPosition()]);
        $this->assertSame('dough-id', $this->recipes->findOneById($edge->getComponentId())->getOriginalId());
    }

    // --- Le plafond du compte est jugé sur « compte + recettes à créer » --------------------------------------------------------------

    public function testTheCopyIsRefusedWhenTheAccountWouldGoOverTheLimitWithTheUsedRecipesAndNothingIsWritten(): void
    {
        // Given : une recette de moins que le plafond ; la tarte et ses deux recettes utilisées font trois recettes à créer
        $this->assemble([...$this->tartAndItsRecipes(), ...$this->recipesOf(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES - 1)]);

        // When
        try {
            ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));
            $this->fail('La copie doit être refusée.');
        } catch (RecipeException $exception) {
            // Then : refus de domaine (422), rien n'est écrit ni annoncé, aucun ingrédient copié
            $this->assertEquals(RecipeException::limitReached(), $exception);
        }
        $this->assertSame(Recipe::MAX_PER_AUTHOR_WITH_COPIES - 1, $this->recipes->findByAuthor(self::COPIER)->count());
        $this->assertCount(0, $this->ingredients->findByOwner(self::COPIER));
        $this->assertSame([], $this->events->eventDispatched);
    }

    public function testTheCopyIsMadeWhenTheAccountReachesTheLimitExactly(): void
    {
        // Given : trois recettes à créer et deux de place
        $this->assemble([...$this->tartAndItsRecipes(), ...$this->recipesOf(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES - 3)]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : exactement le plafond
        $this->assertSame(Recipe::MAX_PER_AUTHOR_WITH_COPIES, $this->recipes->findByAuthor(self::COPIER)->count());
    }

    public function testAReusedCopyDoesNotCountInTheLimit(): void
    {
        // Given : le compte a sa copie de la pâte et de la place pour deux recettes de plus (2 à créer : la crème et la tarte)
        $existing = new RecipeBuilder()->withId('my-dough')->withName('Ma pâte')->withAuthorId(self::COPIER)->withOriginalId('dough-id')
            ->addRow($this->row($this->personal('farine', self::COPIER), $this->unit()->isBox(), 1))->build();
        $this->assemble([...$this->tartAndItsRecipes(), $existing, ...$this->recipesOf(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES - 3)], [$this->personal('farine', self::COPIER)]);

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : sans la réutilisation il en aurait fallu trois
        $this->assertSame(Recipe::MAX_PER_AUTHOR_WITH_COPIES, $this->recipes->findByAuthor(self::COPIER)->count());
    }

    public function testACopyIsRefusedByTheLimitWhenTheReusedCopyIsNotEnoughToStayUnderIt(): void
    {
        // Given : sa copie de la pâte est réutilisée, mais il ne reste de la place que pour une recette (2 à créer)
        $existing = new RecipeBuilder()->withId('my-dough')->withName('Ma pâte')->withAuthorId(self::COPIER)->withOriginalId('dough-id')
            ->addRow($this->row($this->personal('farine', self::COPIER), $this->unit()->isBox(), 1))->build();
        $this->assemble([...$this->tartAndItsRecipes(), $existing, ...$this->recipesOf(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES - 2)], [$this->personal('farine', self::COPIER)]);

        // When / Then
        $this->expectExceptionObject(RecipeException::limitReached());
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));
    }

    // --- Sans composant, rien ne change ----------------------------------------------------------------------------------------------

    public function testARecipeWithoutComponentIsCopiedWithNoLockAndNoReadOfUsedRecipes(): void
    {
        // Given
        $plain = new RecipeBuilder()->withId('plain-id')->withName('Salade')->withAuthorId(self::AUTHOR)->addRow($this->row($this->flour, $this->unit()->isBox(), 1))->build();
        $this->assemble([$plain]);

        // When
        ($this->handler)(new CopyRecipeCommand('plain-id', self::COPIER, 'copy-id'));

        // Then : ni verrou, ni lecture en lot des recettes utilisées, ni recherche de copie : exactement la copie d'avant
        $this->assertSame([], $this->lock->acquired);
        $this->assertSame([0, 0], [$this->recipes->batchReads, $this->recipes->usableCopyReads]);
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertFalse($copy->hasComponents());
        $this->assertFalse($copy->areComponentsChanged());
        $this->assertSame(['copy-id'], array_values($this->events->eventDispatched[0]->recipeIds->toArray()));
    }

    public function testAComposedCopyTakesTheLockOfTheCopierOnceBeforeReadingAndReadsInBatches(): void
    {
        // Given
        $this->assemble($this->tartAndItsRecipes());

        // When
        ($this->handler)(new CopyRecipeCommand('tart-id', self::COPIER, 'copy-id'));

        // Then : le verrou de l'auteur de la copie (jamais celui de l'auteur de l'original), une fois ; une lecture en lot des recettes utilisées, une
        // recherche de copies déjà faites, quel que soit leur nombre
        $this->assertSame([self::COPIER], $this->lock->acquired);
        $this->assertSame([1, 1], [$this->recipes->batchReads, $this->recipes->usableCopyReads]);
    }

    public function testAnOriginalLoadedWithoutItsEdgesIsReadAgainSoTheCopyIsNeverWithoutItsUsedRecipes(): void
    {
        // Given : le modèle que rend la lecture en lot sait qu'il a des composants sans les porter
        $this->assemble($this->tartAndItsRecipes());
        $withoutEdges = $this->recipes->findByIds(['tart-id'])['tart-id'];
        $this->assertTrue($withoutEdges->hasComponents());
        $this->assertCount(0, $withoutEdges->getComponents());

        // When
        $copy = $this->copier->copy($withoutEdges, self::COPIER, 'copy-id');

        // Then
        $this->assertSame(2, $copy->getComponentCount());
        $this->assertCount(3, $this->copiesOf(self::COPIER));
    }

    // --- Aides --------------------------------------------------------------------------------------------------------------------------

    private function unit(): UnitBuilder
    {
        return new UnitBuilder();
    }

    private function builder(string $name, UnitBuilder $unit): IngredientBuilder
    {
        return new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isCheese())->withDefaultCookingUnit($unit)->withDefaultPurchaseUnit($unit);
    }

    private function personal(string $name, string $creatorId, ?UnitBuilder $unit = null): Ingredient
    {
        return $this->builder($name, $unit ?? $this->unit()->isBox())->withCreator(new UserBuilder()->withId($creatorId)->build(), true)->build();
    }

    private function row(Ingredient $ingredient, UnitBuilder $unit, int $quantity): RecipeRowBuilder
    {
        return new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($unit)->withQuantity($quantity);
    }

    /** « Pâte sablée », pour 6 : 250 de farine, 125 de beurre. */
    private function dough(): RecipeBuilder
    {
        return new RecipeBuilder()->withId('dough-id')->withName('pâte sablée')->withAuthorId(self::AUTHOR)->withServing(6)
            ->addRow($this->row($this->flour, $this->unit()->isBox(), 250))->addRow($this->row($this->butter, $this->unit()->isBox(), 125));
    }

    /** « Crème citron », pour 4 : 100 de sucre, 2 citrons. */
    private function cream(): RecipeBuilder
    {
        return new RecipeBuilder()->withId('cream-id')->withName('crème citron')->withAuthorId(self::AUTHOR)->withServing(4)
            ->addRow($this->row($this->sugar, $this->unit()->isBox(), 100))->addRow($this->row($this->lemon, $this->unit()->isBox(), 2));
    }

    /** « Tarte au citron », pour 4 : 100 de farine, 50 de sucre ; elle utilise la pâte 2 fois (rang 0) et la crème 0,5 fois (rang 1). */
    private function tart(RecipeBuilder $dough, RecipeBuilder $cream): RecipeBuilder
    {
        return new RecipeBuilder()->withId('tart-id')->withName('tarte au citron')->withAuthorId(self::AUTHOR)->withServing(4)
            ->addRow($this->row($this->flour, $this->unit()->isBox(), 100))->addRow($this->row($this->sugar, $this->unit()->isBox(), 50))
            ->withComponent($dough, 2.0)->withComponent($cream, 0.5);
    }

    /**
     * @return list<Recipe> la tarte, la pâte et la crème de l'auteur
     */
    private function tartAndItsRecipes(): array
    {
        return [$this->tart($this->dough(), $this->cream())->build(), $this->dough()->build(), $this->cream()->build()];
    }

    /**
     * @return list<Recipe> les recettes d'un compte, dans l'ordre où elles ont été enregistrées
     */
    private function copiesOf(string $authorId): array
    {
        return array_values(array_filter($this->recipes->findAll(), static fn(Recipe $recipe): bool => $recipe->getAuthorId() === $authorId));
    }

    /**
     * @return list<array{0: string, 1: float, 2: int}> (recette utilisée, multiplicateur, rang) de chaque composant
     */
    private function edgesOf(Recipe $recipe): array
    {
        return array_values(array_map(
            static fn(RecipeComponent $edge): array => [$edge->getComponentId(), $edge->getMultiplier(), $edge->getPosition()],
            $recipe->getComponents()->toArray(),
        ));
    }

    /**
     * @return list<string>
     */
    private function componentIdsOf(Recipe $recipe): array
    {
        return array_column($this->edgesOf($recipe), 0);
    }

    /**
     * Les ingrédients des lignes de la recette, sans l'identifiant du compte (le début du slug, `farine`, `sucre`…).
     *
     * @return list<string>
     */
    private function slugsOf(Recipe $recipe, string $owner): array
    {
        return array_values($recipe->getRows()->map(static fn($row): string => str_replace($owner, '', $row->getIngredient()->getSlug()))->toArray());
    }

    private function rowSlug(Recipe $recipe, int $index): string
    {
        return array_values($recipe->getRows()->toArray())[$index]->getIngredient()->getSlug();
    }

    private function copySlug(string $name): string
    {
        return StringTool::slugify($name . self::COPIER);
    }

    /**
     * @return list<Recipe>
     */
    private function recipesOf(string $authorId, int $count): array
    {
        $recipes = [];
        for ($i = 0; $i < $count; $i++) {
            $recipes[] = new RecipeBuilder()->withId("existing-$i")->withAuthorId($authorId)->build();
        }

        return $recipes;
    }
}
