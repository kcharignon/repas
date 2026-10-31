<?php

namespace Repas\Tests\Helper;


use DateTimeImmutable;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Service\ActiveListForType;
use Repas\Repas\Application\Service\PlannedMealGroup;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ListRoutingInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

/**
 * Ce que les handlers qui posent un produit ou une recette dans les listes (ADR 0036, LST-005) reçoivent en plus des repositories : la lecture des
 * destinations, l'ouverture à la demande, le groupe de repas. Tout est en mémoire, construit sur les doubles que le test possède déjà.
 */
final readonly class TypedLists
{
    public ShoppingListTypeInMemoryRepository $listTypes;
    public ListRoutingInMemoryRepository $routing;
    public ActiveListForType $activeListForType;
    public PlannedMealGroup $group;
    public SpyEventDispatcher $events;

    public function __construct(ShoppingListRepository $shoppingLists, IngredientInMemoryRepository $ingredients)
    {
        $this->listTypes = new ShoppingListTypeInMemoryRepository();
        $this->routing = new ListRoutingInMemoryRepository($ingredients, $this->listTypes);
        $this->events = new SpyEventDispatcher();
        $this->activeListForType = new ActiveListForType(
            $shoppingLists,
            new CreateShoppingListHandler($shoppingLists, $this->events, new FrozenClock(new DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE)), $this->listTypes),
            new UuidGenerator(),
        );
        $this->group = new PlannedMealGroup($shoppingLists);
    }

    /**
     * Un type de liste de l'utilisateur, avec les rayons qui lui sont rattachés.
     *
     * @param list<string> $departmentSlugs
     */
    public function type(string $id, string $ownerId, string $name, array $departmentSlugs = []): ShoppingListType
    {
        $type = ShoppingListType::create($id, $ownerId, $name, new DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE))->replaceDepartments($departmentSlugs);
        $this->listTypes->save($type);

        return $type;
    }
}
