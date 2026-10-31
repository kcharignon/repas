<?php

namespace Repas\Tests\Helper;

use DateTimeImmutable;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Application\OpenHostService\IngredientCreationCommandOHS;
use Repas\Repas\Application\OpenHostService\IngredientPhraseCommandOHS;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\IngredientPhraseResolver;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientPhraseCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenPhraseChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Voice\Application\Port\IngredientCreationCommandPort;
use Repas\Voice\Application\Port\IngredientResolutionCommandPort;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\ResolvedIngredient;
use Repas\Voice\Domain\Model\SpokenResolution;
use Repas\Voice\Infrastructure\Acl\IngredientCreationCommandAdapter;
use Repas\Voice\Infrastructure\Acl\IngredientResolutionCommandAdapter;
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
 *    `CreateIngredient` envoyées sur le bus).
 */
final class RepasForVoice
{
    public readonly IngredientPhraseCandidatesInMemoryRepository $candidates;
    public readonly SpokenPhraseChoiceInMemoryRepository $memory;
    public DepartmentInMemoryRepository $departments;
    public UnitInMemoryRepository $units;
    public readonly MockCommandBus $bus;

    /** @var list<array{string, string}> les appels à `resolveOrSuggest` `[userId, phrase]`, dans l'ordre */
    public array $asked = [];

    public function __construct()
    {
        $this->candidates = new IngredientPhraseCandidatesInMemoryRepository();
        $this->memory = new SpokenPhraseChoiceInMemoryRepository();
        $this->bus = new MockCommandBus();
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

    private function candidate(string $slug, string $name, string $departmentName, string $departmentSlug, int $uses): SpokenCandidate
    {
        return new SpokenCandidate($slug, StringTool::slugify($name), $name, $departmentName, $uses, $departmentSlug);
    }
}
