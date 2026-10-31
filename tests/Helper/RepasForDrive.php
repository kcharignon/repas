<?php

namespace Repas\Tests\Helper;

use DateTimeImmutable;
use Repas\Drive\Application\Port\BasketLineCommandPort;
use Repas\Drive\Application\Port\IngredientAliasCommandPort;
use Repas\Drive\Application\Port\IngredientDepartmentCommandPort;
use Repas\Drive\Application\Port\UnitCommandPort;
use Repas\Drive\Infrastructure\Acl\BasketLineCommandAdapter;
use Repas\Drive\Infrastructure\Acl\IngredientAliasCommandAdapter;
use Repas\Drive\Infrastructure\Acl\IngredientDepartmentCommandAdapter;
use Repas\Drive\Infrastructure\Acl\UnitCommandAdapter;
use Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket\CheckRowsAddedToDriveBasketCommand;
use Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket\CheckRowsAddedToDriveBasketHandler;
use Repas\Repas\Application\OpenHostService\BasketLineCheckCommandOHS;
use Repas\Repas\Application\OpenHostService\IngredientAliasCommandOHS;
use Repas\Repas\Application\OpenHostService\IngredientDepartmentCommandOHS;
use Repas\Repas\Application\OpenHostService\UnitCommandOHS;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\Unit;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

/**
 * Ce que les tests des handlers du contexte `Drive` appellent chez `Repas` (ADR 0035) : les vrais adapters du `Drive` et les vrais Open Host
 * Service de `Repas`, branchés sur les doubles en mémoire de ses dépôts (jamais la base). Les dépôts gardent la trace de leurs appels
 * (`resolveCalls`, `departmentSlugsOfCalls`, `existingSlugsCalls`) pour les tests qui prouvent ce que le handler a (ou n'a pas) demandé.
 */
final class RepasForDrive
{
    public readonly IngredientAliasInMemoryRepository $aliases;
    public readonly IngredientInMemoryRepository $ingredients;
    public readonly UnitInMemoryRepository $units;
    /** Le bus par lequel l'Open Host Service d'écriture envoie ses commandes à leur vrai handler (`basketLinePort()`). */
    public readonly MockCommandBus $bus;

    /**
     * @param array<string, string> $aliases le slug de l'ingrédient de l'utilisateur par slug d'alias (anciens slugs de l'utilisateur `$userId`)
     * @param list<string> $notOwned les slugs qui ne sont à personne de l'utilisateur (inconnus, ou à un autre compte) : `resolveOwned()` les omet
     * @param array<string, string> $departments slug du rayon par slug d'ingrédient
     * @param list<string> $units slugs des unités qui existent
     */
    public function __construct(
        array $aliases = [],
        array $notOwned = [],
        array $departments = [],
        array $units = [],
        string $userId = 'user-1',
    ) {
        $this->bus = new MockCommandBus();
        $now = new DateTimeImmutable('2026-10-21 03:04:05');
        $this->aliases = new IngredientAliasInMemoryRepository(
            array_map(
                static fn(string $aliasSlug, string $slug): IngredientAlias => IngredientAlias::create($userId, $aliasSlug, $slug, AliasKind::ADOPTION, 'activation', $now),
                array_keys($aliases),
                array_values($aliases),
            ),
            owns: static fn(string $user, string $slug): bool => !in_array($slug, $notOwned, true),
        );
        $this->ingredients = new IngredientInMemoryRepository(array_map(
            static fn(string $slug, string $department): Ingredient => new IngredientBuilder()->withSlug($slug)->withDepartment(new DepartmentBuilder()->withSlug($department))->build(),
            array_keys($departments),
            array_values($departments),
        ));
        $this->units = new UnitInMemoryRepository(array_map(
            static fn(string $slug): Unit => new UnitBuilder()->withSlug($slug)->build(),
            $units,
        ));
    }

    public function aliasPort(): IngredientAliasCommandPort
    {
        return new IngredientAliasCommandAdapter(new IngredientAliasCommandOHS($this->aliases));
    }

    public function departmentPort(): IngredientDepartmentCommandPort
    {
        return new IngredientDepartmentCommandAdapter(new IngredientDepartmentCommandOHS($this->ingredients));
    }

    public function unitPort(): UnitCommandPort
    {
        return new UnitCommandAdapter(new UnitCommandOHS($this->units));
    }

    /**
     * Cocher les lignes mises au panier (DRV-745) : le vrai adapter, le vrai Open Host Service et le vrai handler de `Repas` sur les dépôts
     * en mémoire donnés (un bus de test qui rend la commande à son handler, sans transaction ni base). `$this->bus` garde les commandes envoyées.
     */
    public function basketLinePort(ShoppingListInMemoryRepository $shoppingLists, ShoppingListRowInMemoryRepository $shoppingListRows): BasketLineCommandPort
    {
        $handler = new CheckRowsAddedToDriveBasketHandler($shoppingLists, $shoppingListRows);
        $this->bus->willAnswer(CheckRowsAddedToDriveBasketCommand::class, static function (object $command) use ($handler): mixed {
            $handler($command);

            return null;
        });

        return new BasketLineCommandAdapter(new BasketLineCheckCommandOHS($this->bus));
    }
}
