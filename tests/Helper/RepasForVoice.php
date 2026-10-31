<?php

namespace Repas\Tests\Helper;

use DateTimeImmutable;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Application\OpenHostService\ActiveListLineLookupCommandOHS;
use Repas\Repas\Application\OpenHostService\ActiveListLineLookupQueryOHS;
use Repas\Repas\Application\OpenHostService\ActiveListMealLookupCommandOHS;
use Repas\Repas\Application\OpenHostService\IngredientCreationCommandOHS;
use Repas\Repas\Application\OpenHostService\IngredientPhraseCommandOHS;
use Repas\Repas\Application\OpenHostService\ShoppingListItemCommandOHS;
use Repas\Repas\Domain\Model\AddedItem as RepasAddedItem;
use Repas\Repas\Domain\Model\RemovedItem as RepasRemovedItem;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ActiveListLookup;
use Repas\Repas\Domain\Service\IngredientPhraseResolver;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryLoader\ActiveShoppingListLinesInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\SpokenIngredientCandidatesInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\SpokenPhraseMemoryInMemoryLoader;
use Repas\Tests\Helper\InMemoryRepository\ActiveShoppingListContentsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientPhraseCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenPhraseChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenRecipePhraseChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Voice\Application\Port\IngredientCreationCommandPort;
use Repas\Voice\Application\Port\IngredientResolutionCommandPort;
use Repas\Voice\Application\Port\ListLineLookupCommandPort;
use Repas\Voice\Application\Port\ListLineLookupQueryPort;
use Repas\Voice\Application\Port\ListMealLookupCommandPort;
use Repas\Voice\Application\Port\ShoppingListItemCommandPort;
use Repas\Voice\Domain\Model\AddedItem;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Model\ListedMeal;
use Repas\Voice\Domain\Model\RemovedItem;
use Repas\Voice\Domain\Model\ResolvedIngredient;
use Repas\Voice\Domain\Model\SpokenResolution;
use Repas\Voice\Domain\Model\SpokenListLookupResult;
use Repas\Voice\Domain\Model\SpokenMealLookupResult;
use Repas\Voice\Infrastructure\Acl\IngredientCreationCommandAdapter;
use Repas\Voice\Infrastructure\Acl\IngredientResolutionCommandAdapter;
use Repas\Voice\Infrastructure\Acl\ListLineLookupCommandAdapter;
use Repas\Voice\Infrastructure\Acl\ListLineLookupQueryAdapter;
use Repas\Voice\Infrastructure\Acl\ListMealLookupCommandAdapter;
use Repas\Voice\Infrastructure\Acl\ShoppingListItemCommandAdapter;
use Throwable;

/**
 * Ce que les tests des handlers du contexte `Voice` appellent chez `Repas` pour les ingrédients (ADR 0035) : les vrais adapters du `Voice`, les
 * vrais Open Host Service de `Repas` et son vrai classeur de noms, branchés sur les doubles en mémoire de ses dépôts et un bus de commandes
 * simulé (jamais la base). Les ingrédients de l'utilisateur et leurs habitudes se programment, c'est le classeur qui choisit.
 *
 *  - `willResolve()` programme les candidats pour que le classeur rende cet ingrédient avec cette confiance : sûr (seul de son nom), probable
 *    (un homonyme d'un autre rayon, bien moins acheté) ou incertain (un jumeau de même nom et de même rayon) ;
 *  - `willTie()` programme des ingrédients de même nom dans plusieurs rayons, que le classeur rend à départager ;
 *  - `asked`, `remembered()` et `created()` disent ce que le handler a demandé (la phrase reçue, les commandes `RememberSpokenPhrase` et
 *    `CreateIngredient` envoyées sur le bus) ;
 *  - la liste de courses (ADR 0035, C7) : `hasActiveList()`, `hasSentList()`, `listHas()` (une ligne de la liste, dont l'ingrédient est à l'utilisateur)
 *    et `listPlans()` (un repas planifié) programment ce qu'elle contient, c'est le classeur qui cherche (`lineLookupPort()`, `lineLookupQueryPort()`
 *    pour une lecture seule, `mealLookupPort()`) ; `itemsPort()` ajoute et retire par les commandes de `Repas` sur le bus simulé : `willAdd()`,
 *    `willRemove()` et `willFailToWriteTheList()` programment leur résultat, `added()`, `removed()` et leurs `…With()` disent ce qui a été demandé,
 *    `lookedUp` et `lookedUpMeals` les phrases cherchées.
 */
final class RepasForVoice
{
    public readonly IngredientPhraseCandidatesInMemoryRepository $candidates;
    public readonly SpokenPhraseChoiceInMemoryRepository $memory;
    public DepartmentInMemoryRepository $departments;
    public UnitInMemoryRepository $units;
    public readonly MockCommandBus $bus;
    public readonly ActiveShoppingListContentsInMemoryRepository $list;
    public readonly SpokenRecipePhraseChoiceInMemoryRepository $recipeMemory;

    private bool $activeList = false;
    private bool $sentList = false;

    /** @var list<\Repas\Repas\Domain\Model\ActiveListLine> */
    private array $lines = [];

    /** @var list<\Repas\Repas\Domain\Model\ActiveListMeal> */
    private array $meals = [];

    /** @var list<array{string, string}> les appels à `resolveOrSuggest` `[userId, phrase]`, dans l'ordre */
    public array $asked = [];

    /** @var list<array{string, string}> les recherches d'une ligne de la liste `[userId, phrase]`, dans l'ordre */
    public array $lookedUp = [];

    /** @var list<array{string, string}> les recherches d'un repas de la liste `[userId, phrase]`, dans l'ordre */
    public array $lookedUpMeals = [];

    public function __construct()
    {
        $this->candidates = new IngredientPhraseCandidatesInMemoryRepository();
        $this->memory = new SpokenPhraseChoiceInMemoryRepository();
        $this->bus = new MockCommandBus();
        $this->list = new ActiveShoppingListContentsInMemoryRepository();
        $this->recipeMemory = new SpokenRecipePhraseChoiceInMemoryRepository();
        $this->willAdd(new AddedItem(listCreated: false, previousListSent: false));
        $this->willRemove(new RemovedItem(found: true, remaining: 0.0, removed: 1.0));
        // Le handler réel rend le slug de l'ingrédient créé : ici, le nom et l'utilisateur
        $this->bus->willAnswer(CreateIngredientCommand::class, static fn(object $command): string => $command->name . '-' . $command->userId);
        $this->setCatalogues(
            [['boucherie', 'boucherie'], ['fruit-et-legume', 'fruit & légume'], ['produit-menager', 'produit ménager']],
            [['kilo', 'kilo'], ['unite', 'unité'], ['paquet', 'paquet']],
        );
    }

    /**
     * Les rayons et les unités du catalogue, `[slug, nom]` chacun.
     *
     * @param list<array{string, string}> $departments
     * @param list<array{string, string}> $units
     */
    public function setCatalogues(array $departments, array $units): self
    {
        $this->departments = new DepartmentInMemoryRepository(array_map(
            static fn(array $department): Department => new DepartmentBuilder()->withSlug($department[0])->withName($department[1])->build(),
            $departments,
        ));
        $this->units = new UnitInMemoryRepository(array_map(
            static fn(array $unit): Unit => new UnitBuilder()->withSlug($unit[0])->withName($unit[1])->build(),
            $units,
        ));

        return $this;
    }

    /**
     * Le classeur rend cet ingrédient pour cette phrase, avec sa confiance. La phrase doit ressembler à son nom (« du pain » pour « Pain »).
     */
    public function willResolve(ResolvedIngredient $ingredient): self
    {
        $department = StringTool::slugify($ingredient->departmentName);
        $habit = $ingredient->confidence === ResolvedIngredient::PROBABLE ? 50 : 0;
        $this->candidates->add($this->candidate($ingredient->slug, $ingredient->name, $ingredient->departmentName, $department, $habit));

        if ($ingredient->confidence === ResolvedIngredient::PROBABLE) {
            // Un homonyme dans un autre rayon, jamais acheté : l'habitude tranche, sans question
            $this->candidates->add($this->candidate($ingredient->slug . '-autre', $ingredient->name, 'Autre rayon', 'autre-rayon', 0));
        }
        if ($ingredient->confidence === ResolvedIngredient::UNSURE) {
            // Un jumeau de même nom et de même rayon : le classeur n'est pas sûr, et une question ne les départagerait pas
            $this->candidates->add($this->candidate($ingredient->slug . '-jumeau', $ingredient->name, $ingredient->departmentName, $department, 0));
        }

        return $this;
    }

    /**
     * Des ingrédients de même nom dans plusieurs rayons, sans habitude : le classeur les rend à départager par le rayon.
     *
     * @param list<DialogueOption> $ties `slug`, `name`, `departmentSlug` et `departmentName` de chacun
     */
    public function willTie(array $ties): self
    {
        foreach ($ties as $tie) {
            $this->candidates->add($this->candidate($tie->slug, $tie->name, $tie->departmentName ?? '', $tie->departmentSlug ?? '', 0));
        }

        return $this;
    }

    /**
     * Un ingrédient de l'utilisateur, sans autre intention que de faire partie de ce qu'il possède (propositions, jamais choisi tout seul).
     */
    public function owns(string $slug, string $name, string $departmentName = 'Épicerie', int $uses = 0): self
    {
        $this->candidates->add($this->candidate($slug, $name, $departmentName, StringTool::slugify($departmentName), $uses));

        return $this;
    }

    /** L'utilisateur a déjà choisi cet ingrédient pour cette phrase (la mémoire passe avant tout classement). */
    public function remembers(string $userId, string $phraseKey, string $ingredientSlug): self
    {
        $this->memory->remember($userId, $phraseKey, $ingredientSlug, new DateTimeImmutable('2026-10-25 10:00:00'));

        return $this;
    }

    public function willFailToRemember(Throwable $failure): self
    {
        $this->bus->willThrow(RememberSpokenPhraseCommand::class, $failure);

        return $this;
    }

    public function willFailToCreate(Throwable $failure): self
    {
        $this->bus->willThrow(CreateIngredientCommand::class, $failure);

        return $this;
    }

    /**
     * @return list<array{string, string, string}> les phrases retenues `[userId, phrase, slug]`, dans l'ordre
     */
    public function remembered(): array
    {
        $remembered = [];
        foreach ($this->bus->dispatched() as $command) {
            if ($command instanceof RememberSpokenPhraseCommand) {
                $remembered[] = [$command->userId, $command->phrase, $command->ingredientSlug];
            }
        }

        return $remembered;
    }

    /**
     * @return list<array{string, string, string, string}> les créations demandées `[userId, nom, rayon, unité]`, dans l'ordre
     */
    public function created(): array
    {
        $created = [];
        foreach ($this->bus->dispatched() as $command) {
            if ($command instanceof CreateIngredientCommand) {
                $created[] = [$command->userId, $command->name, $command->departmentSlug, $command->defaultPurchaseUnitSlug];
            }
        }

        return $created;
    }

    /** Le port des ingrédients, tel que les handlers le reçoivent : la vraie chaîne, qui note la phrase reçue dans `$asked`. */
    public function resolutionPort(): IngredientResolutionCommandPort
    {
        $real = new IngredientResolutionCommandAdapter(
            new IngredientPhraseCommandOHS($this->candidates, $this->memory, new IngredientPhraseResolver(), $this->bus),
        );

        $record = function (string $userId, string $phrase): void {
            $this->asked[] = [$userId, $phrase];
        };

        return new class($real, $record) implements IngredientResolutionCommandPort {
            public function __construct(
                private readonly IngredientResolutionCommandPort $real,
                private readonly \Closure $record,
            ) {
            }

            public function resolveOrSuggest(string $userId, string $phrase): SpokenResolution
            {
                ($this->record)($userId, $phrase);

                return $this->real->resolveOrSuggest($userId, $phrase);
            }

            public function remember(string $userId, string $phrase, string $ingredientSlug): void
            {
                $this->real->remember($userId, $phrase, $ingredientSlug);
            }
        };
    }

    public function creationPort(): IngredientCreationCommandPort
    {
        return new IngredientCreationCommandAdapter(new IngredientCreationCommandOHS($this->departments, $this->units, $this->bus));
    }

    // --- la liste de courses active (VOX-103, VOX-304, VOX-306) ---

    /**
     * L'utilisateur a une liste active, vide pour l'instant (sans elle : « pas de liste en cours »).
     */
    public function hasActiveList(): self
    {
        $this->activeList = true;
        $this->sentList = false;
        $this->syncList();

        return $this;
    }

    /** L'utilisateur n'a pas de liste active mais une liste envoyée au drive (elle n'est ni lue ni modifiée). */
    public function hasSentList(): self
    {
        $this->activeList = false;
        $this->sentList = true;
        $this->syncList();

        return $this;
    }

    /**
     * La liste active porte cette ligne ; l'ingrédient fait partie de ceux de l'utilisateur (le classeur le reconnaît par son nom).
     */
    public function listHas(ListedLine $line, int $uses = 0): self
    {
        $this->hasActiveList();
        $this->lines[] = new \Repas\Repas\Domain\Model\ActiveListLine($line->slug, $line->name, $line->departmentSlug, $line->departmentName, $line->quantity, $line->unitSlug, $line->unitName);
        $this->candidates->add($this->candidate($line->slug, $line->name, $line->departmentName, $line->departmentSlug, $uses));
        $this->syncList();

        return $this;
    }

    /** La liste active planifie ce repas ; la recette est une de celles de l'utilisateur. */
    public function listPlans(ListedMeal $meal): self
    {
        $this->hasActiveList();
        $this->meals[] = new \Repas\Repas\Domain\Model\ActiveListMeal($meal->recipeId, $meal->name, $meal->servings);
        $this->syncList();

        return $this;
    }

    /** L'utilisateur a déjà choisi cette recette pour cette phrase (la mémoire des recettes passe avant tout classement). */
    public function remembersRecipe(string $userId, string $phraseKey, string $recipeId): self
    {
        $this->recipeMemory->remember($userId, $phraseKey, $recipeId, new DateTimeImmutable('2026-10-25 10:00:00'));

        return $this;
    }

    /** Le prochain ajout à la liste rend ce résultat (par défaut : la liste existait, une ligne de plus). */
    public function willAdd(AddedItem $added): self
    {
        $this->bus->willReturn(AddSpokenIngredientCommand::class, new RepasAddedItem(
            $added->listCreated,
            $added->previousListSent,
            $added->quantity,
            $added->unitSlug,
            $added->unitName,
            $added->lineExisted,
            $added->unitSubstituted,
            $added->addedQuantity,
        ));

        return $this;
    }

    /** Les prochains retraits de la liste rendent ce résultat (par défaut : une ligne retirée, plus rien dans la liste). */
    public function willRemove(RemovedItem $removed): self
    {
        $this->bus->willReturn(RemoveSpokenIngredientCommand::class, new RepasRemovedItem(
            $removed->found,
            $removed->remaining,
            $removed->removed,
            $removed->unitSlug,
            $removed->unitName,
            $removed->unitSubstituted,
        ));

        return $this;
    }

    /** L'écriture dans la liste échoue (une exception de domaine, par exemple) : la commande est enregistrée, puis l'exception levée. */
    public function willFailToWriteTheList(Throwable $failure): self
    {
        $this->bus->willThrow(AddSpokenIngredientCommand::class, $failure);
        $this->bus->willThrow(RemoveSpokenIngredientCommand::class, $failure);

        return $this;
    }

    /**
     * @return list<array{string, string}> les ajouts demandés `[userId, ingredientSlug]`, dans l'ordre
     */
    public function added(): array
    {
        $added = [];
        foreach ($this->bus->dispatched() as $command) {
            if ($command instanceof AddSpokenIngredientCommand) {
                $added[] = [$command->ownerId, $command->ingredientSlug];
            }
        }

        return $added;
    }

    /**
     * @return list<array{int, ?string}> la quantité et l'unité dite de chaque ajout, dans le même ordre que `added()`
     */
    public function addedWith(): array
    {
        $added = [];
        foreach ($this->bus->dispatched() as $command) {
            if ($command instanceof AddSpokenIngredientCommand) {
                $added[] = [$command->quantity, $command->unitSlug];
            }
        }

        return $added;
    }

    /**
     * @return list<array{string, string}> les retraits demandés `[userId, ingredientSlug]`, dans l'ordre
     */
    public function removed(): array
    {
        $removed = [];
        foreach ($this->bus->dispatched() as $command) {
            if ($command instanceof RemoveSpokenIngredientCommand) {
                $removed[] = [$command->ownerId, $command->ingredientSlug];
            }
        }

        return $removed;
    }

    /**
     * @return list<array{int, ?string}> la quantité et l'unité dite de chaque retrait, dans le même ordre que `removed()`
     */
    public function removedWith(): array
    {
        $removed = [];
        foreach ($this->bus->dispatched() as $command) {
            if ($command instanceof RemoveSpokenIngredientCommand) {
                $removed[] = [$command->quantity, $command->unitSlug];
            }
        }

        return $removed;
    }

    /** Le port de la liste, tel que les handlers d'écriture le reçoivent : le vrai adapter, le vrai Open Host Service, le bus simulé. */
    public function itemsPort(): ShoppingListItemCommandPort
    {
        return new ShoppingListItemCommandAdapter(new ShoppingListItemCommandOHS($this->bus));
    }

    /** Le port de recherche d'une ligne, côté écriture : la vraie chaîne, qui note les phrases reçues dans `$lookedUp`. */
    public function lineLookupPort(): ListLineLookupCommandPort
    {
        $real = new ListLineLookupCommandAdapter(new ActiveListLineLookupCommandOHS($this->list, $this->candidates, $this->memory, new ActiveListLookup()));
        $record = function (string $userId, string $phrase): void {
            $this->lookedUp[] = [$userId, $phrase];
        };

        return new class($real, $record) implements ListLineLookupCommandPort {
            public function __construct(
                private readonly ListLineLookupCommandPort $real,
                private readonly \Closure $record,
            ) {
            }

            public function find(string $userId, string $phrase): SpokenListLookupResult
            {
                ($this->record)($userId, $phrase);

                return $this->real->find($userId, $phrase);
            }
        };
    }

    /** Le port de recherche d'une ligne, côté lecture : la vraie chaîne sur les Loaders en mémoire de ce qui a été programmé jusqu'ici. */
    public function lineLookupQueryPort(): ListLineLookupQueryPort
    {
        $memory = [];
        foreach ($this->memory->all() as $row) {
            $memory[$row['phrase']] = $row['slug'];
        }
        $real = new ListLineLookupQueryAdapter(new ActiveListLineLookupQueryOHS(
            new ActiveShoppingListLinesInMemoryLoader($this->activeList ? $this->lines : null, $this->sentList),
            new SpokenIngredientCandidatesInMemoryLoader($this->candidates->all()),
            new SpokenPhraseMemoryInMemoryLoader($memory),
            new ActiveListLookup(),
        ));
        $record = function (string $userId, string $phrase): void {
            $this->lookedUp[] = [$userId, $phrase];
        };

        return new class($real, $record) implements ListLineLookupQueryPort {
            public function __construct(
                private readonly ListLineLookupQueryPort $real,
                private readonly \Closure $record,
            ) {
            }

            public function find(string $userId, string $phrase): SpokenListLookupResult
            {
                ($this->record)($userId, $phrase);

                return $this->real->find($userId, $phrase);
            }
        };
    }

    /** Le port de recherche d'un repas planifié : la vraie chaîne, qui note les phrases reçues dans `$lookedUpMeals`. */
    public function mealLookupPort(): ListMealLookupCommandPort
    {
        $real = new ListMealLookupCommandAdapter(new ActiveListMealLookupCommandOHS($this->list, $this->recipeMemory, new ActiveListLookup()));
        $record = function (string $userId, string $phrase): void {
            $this->lookedUpMeals[] = [$userId, $phrase];
        };

        return new class($real, $record) implements ListMealLookupCommandPort {
            public function __construct(
                private readonly ListMealLookupCommandPort $real,
                private readonly \Closure $record,
            ) {
            }

            public function find(string $userId, string $phrase): SpokenMealLookupResult
            {
                ($this->record)($userId, $phrase);

                return $this->real->find($userId, $phrase);
            }
        };
    }

    private function syncList(): void
    {
        $this->list->withLines($this->activeList ? $this->lines : null)->withMeals($this->activeList ? $this->meals : null)->withSentList($this->sentList);
    }

    private function candidate(string $slug, string $name, string $departmentName, string $departmentSlug, int $uses): SpokenCandidate
    {
        return new SpokenCandidate($slug, StringTool::slugify($name), $name, $departmentName, $uses, $departmentSlug);
    }
}
