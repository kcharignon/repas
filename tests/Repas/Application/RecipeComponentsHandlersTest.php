<?php

namespace Repas\Tests\Repas\Application;


use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeHandler;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeHandler;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\RecipeComponentsWriter;
use Repas\Repas\Application\Service\RecipeCopier;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Repas\Domain\Service\RecipeComponentRules;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientMatchCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientPartLinkInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RecipeComponentLockSpy;

/**
 * Écrire les recettes qu'une recette utilise (ADR 0040 décisions 24 à 27, COMP-002), avec les VRAIS cas d'usage `CreateRecipeHandler` et
 * `UpdateRecipeHandler` sur les doubles en mémoire : `components` absent ou `null` = inchangé, `[]` = tout retirer, remplacement, l'ordre est la
 * position, chacun des refus dans l'ordre de la décision 26 (et RIEN n'est enregistré sur un refus), l'administrateur qui compare avec l'auteur, et le
 * verrou de l'auteur pris exactement quand il le faut et AVANT de lire.
 *
 * L'auteur des recettes est `IngredientBuilder::DEFAULT_CREATOR_ID` (le propriétaire par défaut des ingrédients de test).
 */
class RecipeComponentsHandlersTest extends TestCase
{
    private const string AUTHOR = IngredientBuilder::DEFAULT_CREATOR_ID;
    private const string OTHER = 'other-id';
    private const string ADMIN = 'admin-id';

    private RecipeInMemoryRepository $recipes;
    private RecipeComponentLockSpy $lock;
    private CreateRecipeHandler $create;
    private UpdateRecipeHandler $update;
    private Ingredient $pasta;
    private Ingredient $parmesan;

    protected function setUp(): void
    {
        $this->assemble();
    }

    /**
     * @param (Closure(string): void)|null $whileWaiting ce qui se passe PENDANT l'attente du verrou (voir `RecipeComponentLockSpy`)
     */
    private function assemble(?Closure $whileWaiting = null): void
    {
        $this->pasta = new IngredientBuilder()->isPasta()->build();
        $this->parmesan = new IngredientBuilder()->isParmesan()->build();
        $ingredients = new IngredientInMemoryRepository([$this->pasta, $this->parmesan]);
        $units = new UnitInMemoryRepository([new UnitBuilder()->isGramme()->build(), new UnitBuilder()->isUnite()->build(), new UnitBuilder()->isKilo()->build()]);
        $conversions = new ConversionInMemoryRepository([new ConversionBuilder()->isKiloToGramme()->build()]);
        $conversionService = new ConversionService($conversions, $units);
        $aliases = new IngredientAliasInMemoryRepository();
        $slugResolver = new IngredientSlugResolver($aliases);
        $types = new RecipeTypeInMemoryRepository([new RecipeTypeBuilder()->isMeal()->build(), new RecipeTypeBuilder()->isDessert()->build()]);
        $this->recipes = new RecipeInMemoryRepository();
        $this->lock = new RecipeComponentLockSpy($whileWaiting);
        $rules = new RecipeComponentRules();
        $writer = new RecipeComponentsWriter($this->recipes, $this->lock, $rules);
        $events = new SpyEventDispatcher();

        $this->create = new CreateRecipeHandler($types, $ingredients, $units, $this->recipes, $conversionService, $events, $slugResolver, $writer, $rules);
        $this->update = new UpdateRecipeHandler(
            $this->recipes,
            $types,
            $ingredients,
            $units,
            $conversionService,
            $slugResolver,
            new RecipeCopier(
                $this->recipes,
                $ingredients,
                $conversions,
                $slugResolver,
                new IngredientMatchCandidatesInMemoryRepository($ingredients),
                new IngredientNameMatcher(),
                new IngredientSlugPolicy(),
                $conversionService,
                new IngredientPartLinkInMemoryRepository(),
                new UuidGenerator(),
                $events,
            ),
            $writer,
            $rules,
            $this->lock,
        );
    }

    // --- Modifier : absent, null, [], remplacement, position

    public function testAbsentComponentsLeaveTheRecipeUsedRecipesAsTheyWereWithoutTouchingTheLockNorReadingAnything(): void
    {
        // Given : la tarte utilise la pâte (×2) et la crème (×0,5)
        $pie = $this->pieUsing([['dough', 2.0], ['cream', 0.5]]);
        $edgesBefore = $this->edgesOf('pie');

        // When : `components` absent (ancien client), puis `null` explicite : la même chose
        ($this->update)($this->updateCommand('pie', components: null));

        // Then : le drapeau « composants modifiés » est faux, les arêtes sont celles d'avant, ni verrou ni lecture en lot
        $saved = $this->recipes->findOneById('pie');
        $this->assertFalse($saved->areComponentsChanged());
        $this->assertSame($edgesBefore, $this->edgesOf('pie'));
        $this->assertSame([[], 0], [$this->lock->acquired, $this->recipes->batchReads]);
        $this->assertSame($pie->getVersion(), $saved->getVersion());
    }

    public function testAnEmptyListRemovesEveryComponentWithoutTheLockNorAnyRead(): void
    {
        // Given
        $this->pieUsing([['dough', 2.0], ['cream', 0.5]]);

        // When
        ($this->update)($this->updateCommand('pie', components: []));

        // Then : retirer ne peut rien casser : ni verrou ni lecture ; les recettes utilisées restent
        $saved = $this->recipes->findOneById('pie');
        $this->assertSame([[], 0, true], [$this->edgesOf('pie'), $saved->getComponentCount(), $saved->areComponentsChanged()]);
        $this->assertSame([[], 0, 0], [$this->lock->acquired, $this->recipes->batchReads, $this->recipes->usedAsComponentChecks]);
        $this->assertSame('dough', $this->recipes->findOneById('dough')->getId());
    }

    public function testAnEmptyListOnARecipeWithoutComponentsChangesNothing(): void
    {
        // Given
        $this->saved('pie');

        // When
        ($this->update)($this->updateCommand('pie', components: []));

        // Then : zéro composant, rien ne change (le drapeau reste faux)
        $this->assertFalse($this->recipes->findOneById('pie')->areComponentsChanged());
        $this->assertSame([], $this->lock->acquired);
    }

    public function testAReplacementKeepsTheOrderAsThePositionAndTheEdgeOfAPairAlreadyThere(): void
    {
        // Given : la tarte utilise la pâte puis la crème ; une chantilly existe
        $this->pieUsing([['dough', 2.0], ['cream', 0.5]]);
        $this->saved('whipped');
        $doughEdge = $this->recipes->findOneById('pie')->getComponents()->reset()->getId();

        // When : la crème d'abord, la pâte ensuite (autre multiplicateur), puis la chantilly
        ($this->update)($this->updateCommand('pie', components: [['cream', 3.0], ['dough', 1.25], ['whipped', 1.0]]));

        // Then
        $saved = $this->recipes->findOneById('pie');
        $this->assertSame(['cream', 'dough', 'whipped'], $saved->componentIds());
        $this->assertSame([[0, 3.0], [1, 1.25], [2, 1.0]], array_map(static fn($edge): array => [$edge->getPosition(), $edge->getMultiplier()], array_values($saved->getComponents()->toArray())));
        $this->assertSame($doughEdge, $saved->getComponents()[1]->getId(), "la pâte garde l'identifiant de son arête");
        $this->assertTrue($saved->areComponentsChanged());
        $this->assertSame(3, $saved->getComponentCount());
        // une seule lecture en lot, quel que soit le nombre de recettes utilisées ; le verrou de l'AUTEUR, une fois
        $this->assertSame([[self::AUTHOR], 1], [$this->lock->acquired, $this->recipes->batchReads]);
    }

    public function testARecipeMadeOnlyOfUsedRecipesIsAllowedAndOneWithNothingIsNot(): void
    {
        // Given : une tarte avec une ligne, la pâte
        $this->saved('dough');
        $this->saved('pie');

        // When : zéro ligne, une recette utilisée
        ($this->update)($this->updateCommand('pie', components: [['dough', 1.0]], rows: []));

        // Then
        $this->assertSame([0, ['dough']], [$this->recipes->findOneById('pie')->getRows()->count(), $this->recipes->findOneById('pie')->componentIds()]);

        // When : zéro ligne ET zéro recette utilisée
        $this->assertRefused(RecipeException::EMPTY, 422, fn() => ($this->update)($this->updateCommand('pie', components: [], rows: [])));
    }

    public function testZeroRowsKeepTheRecipeValidWhenItKeepsItsComponentsBecauseTheFinalStateIsJudged(): void
    {
        // Given : un ancien client qui renvoie `rows: []` (sans `components`) à une recette qui en a
        $this->pieUsing([['dough', 1.0]]);

        // When & Then : lignes + composants ≥ 1 sur l'état final, la recette garde ses composants : pas de refus
        ($this->update)($this->updateCommand('pie', components: null, rows: []));
        $this->assertSame(['dough'], $this->recipes->findOneById('pie')->componentIds());

        // When & Then : une recette qui n'en a pas et dont le corps n'a aucune ligne est vide
        $this->saved('plain');
        $this->assertRefused(RecipeException::EMPTY, 422, fn() => ($this->update)($this->updateCommand('plain', components: null, rows: [])));
    }

    // --- Les refus jugés sans lecture, puis les refus qui lisent

    /**
     * @return iterable<string, array{list<array{string, float}>, string, int}> les composants, le message, le statut
     */
    public static function refusalsWithoutAnyRead(): iterable
    {
        $unknown = array_map(static fn(int $number): array => ["unknown-$number", 1.0], range(1, 11));
        yield 'onze recettes (avant toute lecture)' => [$unknown, RecipeException::COMPONENT_LIMIT_REACHED, 422];
        yield 'une recette deux fois (avant toute lecture)' => [[['dough', 1.0], ['dough', 2.0]], RecipeException::COMPONENT_DUPLICATE, 422];
        yield 'multiplicateur sous la borne' => [[['dough', 0.0099]], RecipeException::COMPONENT_MULTIPLIER_INVALID, 422];
        yield 'multiplicateur au-dessus de la borne' => [[['dough', 100.0001]], RecipeException::COMPONENT_MULTIPLIER_INVALID, 422];
        yield 'multiplicateur nul' => [[['dough', 0.0]], RecipeException::COMPONENT_MULTIPLIER_INVALID, 422];
        yield 'multiplicateur négatif' => [[['dough', -1.0]], RecipeException::COMPONENT_MULTIPLIER_INVALID, 422];
        yield "multiplicateur qui n'est pas un nombre" => [[['dough', NAN]], RecipeException::COMPONENT_MULTIPLIER_INVALID, 422];
        yield 'multiplicateur infini' => [[['dough', INF]], RecipeException::COMPONENT_MULTIPLIER_INVALID, 422];
        yield 'la recette elle-même' => [[['pie', 1.0]], RecipeException::COMPONENT_SELF, 422];
    }

    /**
     * @param list<array{string, float}> $components
     */
    #[DataProvider('refusalsWithoutAnyRead')]
    public function testTheBodyIsJudgedBeforeAnyReadAndBeforeTheLock(array $components, string $message, int $status): void
    {
        // Given
        $this->saved('dough');
        $this->saved('pie');

        // When & Then : le refus, rien de lu, aucun verrou, rien d'enregistré
        $savesBefore = $this->recipes->saves;
        $this->assertRefused($message, $status, fn() => ($this->update)($this->updateCommand('pie', components: $components)));
        $this->assertSame([[], 0, 0, $savesBefore], [$this->lock->acquired, $this->recipes->batchReads, $this->recipes->usedAsComponentChecks, $this->recipes->saves]);
        $this->assertSame([], $this->recipes->findOneById('pie')->componentIds());
    }

    public function testTheMultiplierBoundsThemselvesAreAccepted(): void
    {
        // Given
        $this->saved('dough');
        $this->saved('pie');

        // When & Then
        foreach ([0.01, 100.0] as $multiplier) {
            ($this->update)($this->updateCommand('pie', components: [['dough', $multiplier]]));
            $this->assertSame($multiplier, $this->recipes->findOneById('pie')->getComponents()->reset()->getMultiplier());
        }
    }

    /**
     * @return iterable<string, array{string, string, int}> un nom de cas, le message, le statut
     */
    public static function refusalsThatRead(): iterable
    {
        yield 'recette utilisée inconnue' => ['unknown', 'Recipe id unknown not found', 404];
        yield "recette utilisée d'un autre compte" => ['of-another', 'Recipe id of-another not found', 404];
        yield "recette utilisée secrète d'un autre compte" => ['secret-of-another', 'Recipe id secret-of-another not found', 404];
        yield 'la recette est déjà utilisée par une autre' => ['pie-is-used', RecipeException::COMPONENT_CHAIN, 422];
        yield 'la recette utilisée a ses propres composants' => ['used-has-components', RecipeException::COMPONENT_CHAIN, 422];
        yield 'une recette secrète pour une recette ordinaire' => ['secret-for-ordinary', RecipeException::COMPONENT_SECRET, 409];
    }

    #[DataProvider('refusalsThatRead')]
    public function testEveryRefusalThatReadsHasItsStatusTheLockWasTakenBeforeAndNothingIsSaved(string $case, string $message, int $status): void
    {
        // Given
        $this->saved('dough');
        $this->saved('pie');
        $this->saved('of-another', author: self::OTHER);
        $this->saved('secret-of-another', author: self::OTHER, secret: true);
        $this->saved('secret', secret: true);
        $this->saved('menu', uses: [['pie', 1.0]]);
        $this->pieUsing([['dough', 1.0]], id: 'composed');
        $target = match ($case) {
            'pie-is-used' => 'dough',
            'used-has-components' => 'composed',
            'secret-for-ordinary' => 'secret',
            default => $case,
        };
        // 'pie' est utilisée par 'menu' dans tous les cas ; pour les autres cas, on écrit sur une tarte libre
        $recipeId = $case === 'pie-is-used' ? 'pie' : 'free-pie';
        $this->saved('free-pie');
        $savesBefore = $this->recipes->saves;

        // When & Then
        $this->assertRefused($message, $status, fn() => ($this->update)($this->updateCommand($recipeId, components: [[$target, 1.0]])));
        $this->assertSame($savesBefore, $this->recipes->saves, 'Rien n\'est enregistré sur un refus.');
        $this->assertSame([self::AUTHOR], $this->lock->acquired, 'Le verrou est pris avant de lire.');
        $this->assertSame(1, $this->recipes->batchReads);
    }

    public function testTheOrderOfTheControlsIsTheOneOfTheAdrWhenSeveralFaultsMeet(): void
    {
        // Given : une tarte déjà utilisée (profondeur), une recette secrète (règle du secret), un identifiant inconnu (404)
        $this->saved('dough');
        $this->saved('secret', secret: true);
        $this->saved('pie');
        $this->saved('menu', uses: [['pie', 1.0]]);

        // Then : le nombre avant tout, les doublons avant la lecture
        $this->assertRefused(RecipeException::COMPONENT_LIMIT_REACHED, 422, fn() => ($this->update)($this->updateCommand('pie', components: array_map(static fn(int $n): array => ["x$n", 1.0], range(1, 11)))));
        $this->assertRefused(RecipeException::COMPONENT_DUPLICATE, 422, fn() => ($this->update)($this->updateCommand('pie', components: [['x', 1.0], ['x', 1.0]])));
        // l'inconnue (404) avant la profondeur (la tarte est utilisée) et avant le secret, même quand elle vient APRÈS une recette fautive
        $this->assertRefused('Recipe id x not found', 404, fn() => ($this->update)($this->updateCommand('pie', components: [['secret', 1.0], ['x', 1.0]])));
        // la profondeur (422) avant le secret (409)
        $this->assertRefused(RecipeException::COMPONENT_CHAIN, 422, fn() => ($this->update)($this->updateCommand('pie', components: [['secret', 1.0], ['dough', 1.0]])));
    }

    public function testTheReadsHappenAfterTheLockSoAConcurrentWriteIsSeen(): void
    {
        // Given : pendant l'attente du verrou, une autre transaction donne des composants à la pâte (elle l'utilisait pas encore) : la tarte, qui veut
        // utiliser la pâte, doit le voir
        $this->assemble(function (string $author): void {
            $this->recipes->save($this->recipe('dough', uses: [['cream', 1.0]]));
        });
        $this->saved('cream');
        $this->saved('dough');
        $this->saved('pie');

        // When & Then : la pâte lue APRÈS le verrou a maintenant des composants : profondeur 1 refusée
        $this->assertRefused(RecipeException::COMPONENT_CHAIN, 422, fn() => ($this->update)($this->updateCommand('pie', components: [['dough', 1.0]])));
    }

    // --- L'administrateur : on compare avec l'auteur de la recette

    public function testAnAdministratorEditingTheRecipeOfAnotherAuthorUsesThatAuthorsRecipesOnly(): void
    {
        // Given : la tarte de l'auteur, sa pâte, la pâte de l'administrateur, la crème secrète de l'auteur
        $this->saved('pie');
        $this->saved('dough');
        $this->saved('admin-dough', author: self::ADMIN);
        $this->saved('cream', secret: true);

        // When & Then : une recette de l'administrateur est inconnue pour cette tarte ; la crème secrète de l'auteur, aussi (jamais un `409`)
        $this->assertRefused('Recipe id admin-dough not found', 404, fn() => ($this->update)($this->asAdministrator($this->updateCommand('pie', components: [['admin-dough', 1.0]]))));
        $this->assertRefused('Recipe id cream not found', 404, fn() => ($this->update)($this->asAdministrator($this->updateCommand('pie', components: [['cream', 1.0]]))));

        // When : la pâte de l'AUTEUR
        ($this->update)($this->asAdministrator($this->updateCommand('pie', components: [['dough', 2.0]])));

        // Then
        $this->assertSame(['dough'], $this->recipes->findOneById('pie')->componentIds());
        // et le verrou est celui de l'auteur de la recette, pas celui de l'administrateur
        $this->assertSame([self::AUTHOR, self::AUTHOR, self::AUTHOR], $this->lock->acquired);
    }

    public function testAnAdministratorWhoOwnsTheRecipeMayUseItsSecretOnesLikeAnyAuthor(): void
    {
        // Given : l'administrateur écrit SA tarte secrète et utilise SA crème secrète
        $this->saved('cream', author: self::ADMIN, secret: true);
        $this->saved('pie', author: self::ADMIN, secret: true);

        // When : une recette faite seulement de sa crème (ses ingrédients ne sont pas ceux de l'auteur des essais)
        ($this->update)($this->asAdministrator($this->updateCommand('pie', components: [['cream', 1.0]], rows: [])));

        // Then
        $this->assertSame(['cream'], $this->recipes->findOneById('pie')->componentIds());
        $this->assertSame([self::ADMIN], $this->lock->acquired);
    }

    // --- Les deux bascules du secret et le verrou

    public function testBecomingSecretIsRefusedWhileAnOrdinaryRecipeUsesItAndTakesTheLockFirst(): void
    {
        // Given : la tarte (ordinaire) utilise la pâte
        $this->pieUsing([['dough', 1.0]]);

        // When & Then
        $this->assertRefused(RecipeException::SECRET_USED_BY_PUBLIC, 409, fn() => ($this->update)($this->updateCommand('dough', secret: true)));
        $this->assertSame([self::AUTHOR], $this->lock->acquired);
        $this->assertFalse($this->recipes->findOneById('dough')->isSecret());

        // When : la tarte devient secrète d'abord
        ($this->update)($this->updateCommand('pie', secret: true));
        ($this->update)($this->updateCommand('dough', secret: true));

        // Then
        $this->assertTrue($this->recipes->findOneById('dough')->isSecret());
    }

    public function testBecomingVisibleIsRefusedWhileTheRecipeUsesASecretOneUnlessTheListIsReplacedInTheSameRequest(): void
    {
        // Given : une tarte secrète utilise une pâte secrète
        $this->saved('dough', secret: true);
        $this->saved('plain');
        $this->saved('pie', secret: true, uses: [['dough', 1.0]]);

        // When & Then : rendue visible sans toucher aux composants
        $this->assertRefused(RecipeException::COMPONENT_SECRET, 409, fn() => ($this->update)($this->updateCommand('pie', secret: false)));
        $this->assertTrue($this->recipes->findOneById('pie')->isSecret());

        // When : rendue visible avec une liste ordinaire dans la MÊME requête : c'est l'état final qui est jugé, le verrou n'est pris qu'une fois
        $this->lock->acquired = [];
        ($this->update)($this->updateCommand('pie', secret: false, components: [['plain', 1.0]]));

        // Then
        $visible = $this->recipes->findOneById('pie');
        $this->assertSame([false, ['plain']], [$visible->isSecret(), $visible->componentIds()]);
        $this->assertSame([self::AUTHOR], $this->lock->acquired);

        // When : rendue visible avec une liste qui garde un composant secret : la liste envoyée est jugée
        $this->saved('pie2', secret: true);
        $this->assertRefused(RecipeException::COMPONENT_SECRET, 409, fn() => ($this->update)($this->updateCommand('pie2', secret: false, components: [['dough', 1.0]])));
    }

    public function testBecomingVisibleWithoutAnyComponentDoesNotAskWhetherItUsesASecretOne(): void
    {
        // Given
        $this->saved('pie', secret: true);

        // When
        ($this->update)($this->updateCommand('pie', secret: false));

        // Then : c'est un changement de secret (le verrou), pas de question de plus
        $this->assertFalse($this->recipes->findOneById('pie')->isSecret());
        $this->assertSame([self::AUTHOR], $this->lock->acquired);
    }

    public function testAnotherAuthorCannotFlipTheSecretOfARecipeAndNothingIsLocked(): void
    {
        // Given : l'administrateur modifie la recette ordinaire de l'auteur
        $this->saved('pie');

        // When & Then : `403`, le droit se juge avant le verrou
        $this->assertRefused(RecipeException::SECRET_FORBIDDEN, 403, fn() => ($this->update)($this->asAdministrator($this->updateCommand('pie', secret: true))));
        $this->assertSame([], $this->lock->acquired);
    }

    /**
     * @return iterable<string, array{Closure(self): void, int}> ce qui s'écrit, le nombre de prises du verrou
     */
    public static function lockCases(): iterable
    {
        yield 'modifier avec des composants non vides' => [static fn(self $t) => ($t->update)($t->updateCommand('pie', components: [['dough', 1.0]])), 1];
        yield 'créer avec des composants non vides' => [static fn(self $t) => ($t->create)($t->createCommand('new-pie', components: [['dough', 1.0]])), 1];
        yield 'changer le secret' => [static fn(self $t) => ($t->update)($t->updateCommand('pie', secret: true)), 1];
        yield 'changer le secret ET poser des composants (une seule prise)' => [static fn(self $t) => ($t->update)($t->updateCommand('pie', secret: true, components: [['dough', 1.0]])), 1];
        yield 'modifier sans toucher aux composants (absent)' => [static fn(self $t) => ($t->update)($t->updateCommand('pie')), 0];
        yield 'retirer tous les composants ([])' => [static fn(self $t) => ($t->update)($t->updateCommand('pie', components: [])), 0];
        yield 'envoyer le secret actuel' => [static fn(self $t) => ($t->update)($t->updateCommand('pie', secret: false)), 0];
        yield 'créer sans composant' => [static fn(self $t) => ($t->create)($t->createCommand('new-pie')), 0];
        yield 'créer avec une liste vide' => [static fn(self $t) => ($t->create)($t->createCommand('new-pie', components: [])), 0];
    }

    /**
     * @param Closure(self): void $write
     */
    #[DataProvider('lockCases')]
    public function testTheLockOfTheAuthorIsTakenExactlyWhenAComponentWriteOrASecretChangeNeedsIt(Closure $write, int $taken): void
    {
        // Given
        $this->saved('dough');
        $this->saved('pie');

        // When
        $write($this);

        // Then
        $this->assertSame(array_fill(0, $taken, self::AUTHOR), $this->lock->acquired);
    }

    // --- Créer

    public function testACreatedRecipeCarriesItsComponentsInOrderAndTheLockIsTakenBeforeReadingThem(): void
    {
        // Given
        $this->saved('dough');
        $this->saved('cream');

        // When
        ($this->create)($this->createCommand('new-pie', components: [['cream', 0.5], ['dough', 2.0]]));

        // Then : l'ordre est la position, la recette neuve n'est « utilisée » par personne (aucune question de plus)
        $saved = $this->recipes->findOneById('new-pie');
        $this->assertSame(['cream', 'dough'], $saved->componentIds());
        $this->assertSame([[0, 0.5], [1, 2.0]], array_map(static fn($edge): array => [$edge->getPosition(), $edge->getMultiplier()], array_values($saved->getComponents()->toArray())));
        $this->assertSame([[self::AUTHOR], 1, 0], [$this->lock->acquired, $this->recipes->batchReads, $this->recipes->usedAsComponentChecks]);
        $this->assertSame(2, $saved->getComponentCount());
    }

    public function testACreatedRecipeWithoutComponentsReadsNothingMoreThanBefore(): void
    {
        // When
        ($this->create)($this->createCommand('new-pie'));

        // Then : zéro composant = ni verrou ni lecture en lot
        $this->assertSame([[], 0, false], [$this->lock->acquired, $this->recipes->batchReads, $this->recipes->findOneById('new-pie')->areComponentsChanged()]);
    }

    public function testACreatedRecipeIsRefusedWithoutAnyLineNorUsedRecipeAndWritesNothing(): void
    {
        // When & Then : l'ancien client qui envoie `rows: []` reçoit toujours `422`
        $this->assertRefused(RecipeException::EMPTY, 422, fn() => ($this->create)($this->createCommand('new-pie', rows: [])));
        $this->assertRefused(RecipeException::EMPTY, 422, fn() => ($this->create)($this->createCommand('new-pie', rows: [], components: [])));
        $this->assertSame(0, $this->recipes->saves);
    }

    public function testACreatedRecipeMayBeMadeOnlyOfUsedRecipes(): void
    {
        // Given
        $this->saved('dough');

        // When
        ($this->create)($this->createCommand('only', rows: [], components: [['dough', 1.0]]));

        // Then
        $this->assertSame([0, ['dough']], [$this->recipes->findOneById('only')->getRows()->count(), $this->recipes->findOneById('only')->componentIds()]);
    }

    /**
     * @return iterable<string, array{string, string, int}> un nom de cas, le message, le statut
     */
    public static function createRefusals(): iterable
    {
        yield 'recette utilisée inconnue' => ['unknown', 'Recipe id unknown not found', 404];
        yield "recette utilisée d'un autre compte" => ['of-another', 'Recipe id of-another not found', 404];
        yield "recette utilisée secrète d'un autre compte" => ['secret-of-another', 'Recipe id secret-of-another not found', 404];
        yield 'la recette utilisée a ses propres composants' => ['composed', RecipeException::COMPONENT_CHAIN, 422];
        yield 'une recette secrète pour une recette ordinaire' => ['secret', RecipeException::COMPONENT_SECRET, 409];
    }

    #[DataProvider('createRefusals')]
    public function testACreationRefusedByTheComponentRulesWritesNothing(string $used, string $message, int $status): void
    {
        // Given
        $this->saved('dough');
        $this->saved('of-another', author: self::OTHER);
        $this->saved('secret-of-another', author: self::OTHER, secret: true);
        $this->saved('secret', secret: true);
        $this->pieUsing([['dough', 1.0]], id: 'composed');
        $savesBefore = $this->recipes->saves;

        // When & Then
        $this->assertRefused($message, $status, fn() => ($this->create)($this->createCommand('new-pie', components: [[$used, 1.0]])));
        $this->assertSame($savesBefore, $this->recipes->saves);
    }

    public function testASecretRecipeIsCreatedWithBothOrdinaryAndSecretComponents(): void
    {
        // Given
        $this->saved('dough');
        $this->saved('secret', secret: true);

        // When
        ($this->create)($this->createCommand('new-pie', secret: true, components: [['dough', 1.0], ['secret', 1.0]]));

        // Then
        $this->assertSame(['dough', 'secret'], $this->recipes->findOneById('new-pie')->componentIds());
    }

    public function testTheMultiplierIsNeverTrustedFromTheCommand(): void
    {
        // When & Then : un composant mal formé qui aurait franchi la frontière HTTP est refusé aussi à la création
        $this->saved('dough');
        $this->assertRefused(RecipeException::COMPONENT_MULTIPLIER_INVALID, 422, fn() => ($this->create)($this->createCommand('new-pie', components: [['dough', 101.0]])));
    }

    // --- Aides

    private function assertRefused(string $message, int $status, Closure $write): void
    {
        try {
            $write();
        } catch (RecipeException $exception) {
            $this->assertSame([$status, $message], [$exception->getCode(), $exception->getMessage()]);

            return;
        }
        $this->fail("Le refus `$message` ($status) aurait dû être levé.");
    }

    /**
     * @param list<array{string, float}> $components
     * @param list<array{string, string, float}>|null $rows
     */
    public function updateCommand(string $id, ?array $components = null, ?bool $secret = null, ?array $rows = null): UpdateRecipeCommand
    {
        $rows ??= [['pate', 'gramme', 500.0]];

        return new UpdateRecipeCommand(
            $id,
            'Une recette',
            4,
            $this->tab(UpdateRecipeRowSubCommand::class, array_map(static fn(array $row): UpdateRecipeRowSubCommand => new UpdateRecipeRowSubCommand(...$row), $rows)),
            'plat',
            secret: $secret,
            userId: self::AUTHOR,
            components: $components === null ? null : $this->components($components),
        );
    }

    /**
     * @param list<array{string, float}> $components
     * @param list<array{string, string, float}>|null $rows
     */
    public function createCommand(string $id, ?array $components = null, bool $secret = false, ?array $rows = null): CreateRecipeCommand
    {
        $rows ??= [['pate', 'gramme', 500.0]];

        return new CreateRecipeCommand(
            $id,
            'Une recette',
            4,
            self::AUTHOR,
            $this->tab(CreateRecipeRowSubCommand::class, array_map(static fn(array $row): CreateRecipeRowSubCommand => new CreateRecipeRowSubCommand(...$row), $rows)),
            'plat',
            $secret,
            $components === null ? null : $this->components($components),
        );
    }

    private function asAdministrator(UpdateRecipeCommand $command): UpdateRecipeCommand
    {
        return new UpdateRecipeCommand(
            $command->id, $command->name, $command->serving, $command->rows, $command->typeSlug,
            expectedVersion: $command->expectedVersion, secret: $command->secret, userId: self::ADMIN, byAdministrator: true, components: $command->components,
        );
    }

    /**
     * @param list<array{string, float}> $components
     * @return Tab<RecipeComponentSubCommand>
     */
    private function components(array $components): Tab
    {
        return $this->tab(RecipeComponentSubCommand::class, array_map(static fn(array $component): RecipeComponentSubCommand => new RecipeComponentSubCommand($component[0], $component[1]), $components));
    }

    /**
     * @param class-string $type
     * @param list<object> $items
     */
    private function tab(string $type, array $items): Tab
    {
        return new Tab($items, $type);
    }

    /**
     * @return list<array{string, float, int}> les arêtes d'une recette : recette utilisée, multiplicateur, position
     */
    private function edgesOf(string $id): array
    {
        return array_map(
            static fn($edge): array => [$edge->getComponentId(), $edge->getMultiplier(), $edge->getPosition()],
            array_values($this->recipes->findOneById($id)->getComponents()->toArray()),
        );
    }

    /**
     * @param list<array{string, float}> $uses
     */
    private function recipe(string $id, string $author = self::AUTHOR, bool $secret = false, array $uses = []): Recipe
    {
        $builder = new RecipeBuilder()->withId($id)->withName("Recette $id")->withAuthorId($author)->withServing(4)->withRecipeType(new RecipeTypeBuilder()->isMeal())->asSecret($secret)
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->isPasta())->withUnit(new UnitBuilder()->isGramme())->withQuantity(500));
        foreach ($uses as [$used, $multiplier]) {
            $builder->withComponent($used instanceof Recipe ? $used : $this->recipes->findOneById($used), $multiplier);
        }

        return $builder->build();
    }

    /**
     * @param list<array{string, float}> $uses
     */
    private function saved(string $id, string $author = self::AUTHOR, bool $secret = false, array $uses = []): Recipe
    {
        $recipe = $this->recipe($id, $author, $secret, $uses);
        $this->recipes->save($recipe);

        return $recipe;
    }

    /**
     * La tarte (`$id`) qui utilise ces recettes, créées au passage : elles ne sont jamais écrites par l'essai.
     *
     * @param list<array{string, float}> $uses
     */
    private function pieUsing(array $uses, string $id = 'pie'): Recipe
    {
        foreach ($uses as [$used]) {
            if (!$this->exists($used)) {
                $this->saved($used);
            }
        }

        return $this->saved($id, uses: $uses);
    }

    private function exists(string $id): bool
    {
        try {
            $this->recipes->findOneById($id);

            return true;
        } catch (RecipeException) {
            return false;
        }
    }
}
