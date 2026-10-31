<?php

namespace Repas\Tests\Helper;


use DateTimeImmutable;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserAppVersionBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\Builder\UserIdentityBuilder;
use Repas\Repas\Domain\Interface\IngredientSeedingFeature;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Interface\UserIdentityRepository;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\User;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Interface\AuthorizationCodeRepository;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\AuthorizationCode;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\VoiceLink;

/**
 * Mesure le nombre de requêtes SQL d'un use case d'écriture, de bout en bout : bus de commandes, handler, repositories
 * et listeners d'événements réels, sur la vraie base de test (voir ADR 0010 et roadmap, phase 8).
 *
 * La mesure part d'un état « requête HTTP neuve » : les données sont créées par le scénario, puis l'identity map de
 * Doctrine est vidée avant de compter.
 */
abstract class WriteBudgetTestCase extends DatabaseTestCase
{
    public DataPersister $persister;
    private bool $ingredientSeedingSwitchedOn = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new DataPersister(static::getContainer());
    }

    /**
     * @param callable(self): (object|\Closure) $arrange crée les données du scénario et renvoie la commande à exécuter, ou, pour un service d'application
     *                                       qui n'est pas encore derrière une commande, la fonction à exécuter dans une transaction (comme le bus des commandes)
     * @return array{int, array<string, int>} nombre de requêtes, et leur répartition par type et par table
     */
    public function measure(callable $arrange): array
    {
        $container = static::getContainer();

        $command = $arrange($this);

        $this->entityManager->clear();
        $counter = $container->get(QueryCounter::class);
        $counter->reset();

        if ($command instanceof \Closure) {
            $this->entityManager->wrapInTransaction($command);
        } else {
            $container->get(CommandBusInterface::class)->dispatch($command);
        }

        return [$counter->count(), $counter->byTable()];
    }

    /**
     * Un service du conteneur de test (public), pour un scénario qui appelle un service d'application sans passer par une commande.
     */
    public function service(string $id): object
    {
        return static::getContainer()->get($id);
    }

    public function savedUser(UserBuilder $builder = new UserBuilder()): User
    {
        $user = $builder->build();
        $this->persister->saveUser($user);

        return $user;
    }

    /**
     * Lien de confirmation d'adresse déjà envoyé à `$user` (émis `$minutesAgo` minutes avant maintenant).
     */
    public function savedEmailVerificationToken(User $user, string $secret, int $minutesAgo = 0): void
    {
        static::getContainer()->get(EmailVerificationTokenRepository::class)->add(
            EmailVerificationToken::issue(UuidGenerator::new(), $user->getId(), EmailVerificationToken::hash($secret), new DateTimeImmutable("-{$minutesAgo} minutes")),
        );
    }

    /**
     * Lien « mot de passe oublié » déjà envoyé à `$user` (émis `$minutesAgo` minutes avant maintenant).
     */
    public function savedPasswordResetToken(User $user, string $secret, int $minutesAgo = 0): void
    {
        static::getContainer()->get(PasswordResetTokenRepository::class)->add(
            PasswordResetToken::issue(UuidGenerator::new(), $user->getId(), PasswordResetToken::hash($secret), new DateTimeImmutable("-{$minutesAgo} minutes")),
        );
    }

    /**
     * Lien Google de `$user` (sujet factice).
     */
    public function savedUserIdentity(User $user): void
    {
        static::getContainer()->get(UserIdentityRepository::class)->save(new UserIdentityBuilder()->withUser($user)->build());
    }

    /**
     * Version de l'application vue pour `$user` (`0.1.8`, Android).
     */
    public function savedUserAppVersion(User $user): void
    {
        static::getContainer()->get(UserAppVersionRepository::class)->save(new UserAppVersionBuilder()->withUser($user)->build());
    }

    /**
     * Liaison de `$user` avec Alexa (VOX-202), qu'Alexa n'a pas encore utilisée (sauf si `$alexaUserId` ou `$lastUsedAt` disent le contraire, VOX-204).
     */
    public function savedVoiceLink(User $user, string $id = 'link-budget', ?string $alexaUserId = null, ?DateTimeImmutable $lastUsedAt = null): VoiceLink
    {
        $link = VoiceLink::load([
            'id' => $id,
            'user_id' => $user->getId(),
            'alexa_user_id' => $alexaUserId,
            'created_at' => new DateTimeImmutable('-1 day'),
            'last_used_at' => $lastUsedAt,
            'revoked_at' => null,
        ]);
        static::getContainer()->get(VoiceLinkRepository::class)->save($link);

        return $link;
    }

    /**
     * Paire de jetons de la liaison (VOX-202), dont les secrets sont `$accessToken` et `$refreshToken` (la base n'en garde que le haché).
     */
    public function savedAccessGrant(VoiceLink $link, string $accessToken, string $refreshToken, string $age = '-1 hour', bool $refreshUsed = false): void
    {
        $repository = static::getContainer()->get(AccessGrantRepository::class);
        $repository->add(AccessGrant::issue(OpaqueToken::hash($accessToken), OpaqueToken::hash($refreshToken), $link->getId(), $link->getUserId(), new DateTimeImmutable($age)));
        if ($refreshUsed) {
            $repository->consumeRefresh(OpaqueToken::hash($refreshToken), new DateTimeImmutable('-30 minutes'));
        }
    }

    /**
     * Code d'autorisation déjà émis à `$user` (VOX-202), dont le secret est `$code` : l'échanger demande `$challenge` (le défi PKCE).
     */
    public function savedAuthorizationCode(User $user, string $code, string $challenge, string $age = '-1 minute'): void
    {
        static::getContainer()->get(AuthorizationCodeRepository::class)->add(
            AuthorizationCode::issue(OpaqueToken::hash($code), $user->getId(), AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, $challenge, new DateTimeImmutable($age)),
        );
    }

    /**
     * Réglages du drive de `$user` (magasin factice) : un `SaveDriveSettings` ultérieur les remplace. `$retailer` : l'enseigne par défaut
     * du registre quand il n'est pas dit.
     */
    public function savedDriveSettings(User $user, ?Retailer $retailer = null): void
    {
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->withRetailer($retailer ?? Retailer::default())->build());
    }

    /**
     * Allume toutes les enseignes du registre (la production n'allume que la première, `DRIVE_RETAILERS`) : à appeler AVANT de lancer la
     * commande, un service déjà utilisé ne se remplace plus dans le conteneur de test.
     */
    public function allRetailersEnabled(): void
    {
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(true));
    }

    /**
     * Drive habituel et dernière enseigne de `$user` (DRV-703).
     */
    public function savedRetailerPreference(User $user, ?Retailer $default, ?Retailer $lastUsed): void
    {
        $now = new DateTimeImmutable('2026-10-30 10:00:00');
        $preference = RetailerPreference::create($user->getId(), $now)->chooseDefault($default, $now);
        if ($lastUsed !== null) {
            $preference->rememberLastUsed($lastUsed, $now);
        }
        static::getContainer()->get(RetailerPreferenceRepository::class)->save($preference);
    }

    /**
     * Préférence de `$user` pour un ingrédient dans son magasin factice, avec ses produits (connus de `drive_product`) : les
     * identifiants sont ceux de l'enseigne, le préféré d'abord.
     *
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    public function savedDriveChoice(User $user, string $slug, array $accepted, array $rejected = []): void
    {
        $retailerProductIds = [...$accepted, ...$rejected];
        $productId = static fn(string $retailerProductId): string => Product::idFor(Retailer::LECLERC, '123456', $retailerProductId);

        static::getContainer()->get(ProductRepository::class)->saveAll(array_map(
            static fn(string $retailerProductId): Product => new ProductBuilder()->withRetailerProductId($retailerProductId)->build(),
            $retailerProductIds,
        ));
        static::getContainer()->get(ChoiceRepository::class)->save(
            new ChoiceBuilder()->withUserId($user->getId())->withIngredientSlug($slug)->withAccepted(array_map($productId, $accepted))->withRejected(array_map($productId, $rejected))->build(),
        );
    }

    /**
     * Le catalogue de communs de la migration d'adoption (ING-003, 262 communs dont 105 conversions propres, créé une seule fois) et un
     * volume de recettes, de listes (dont deux envoyées) et de choix du drive pour `$owner`, tous sur des communs.
     */
    public function savedAdoptionVolume(User $owner, int $recipes, int $rowsPerRecipe, int $lists, int $rowsPerList, int $choices): void
    {
        $withCatalog = (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM ingredient WHERE slug LIKE 'commun-%'") === 0;
        AdoptionDataset::createBulk($this->entityManager, $this->persister, $owner->getId(), $recipes, $rowsPerRecipe, $lists, $rowsPerList, $choices, $withCatalog);
    }

    /**
     * L'amorçage des ingrédients à l'activation (ING-008) allumé pour ce test : en test la variable d'environnement le coupe, comme la
     * production par défaut, et les budgets des activations ordinaires sont mesurés interrupteur coupé.
     */
    public function switchOnIngredientSeeding(): void
    {
        // Un service déjà initialisé ne se remplace plus : une seule fois par test
        if ($this->ingredientSeedingSwitchedOn) {
            return;
        }
        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
        $this->ingredientSeedingSwitchedOn = true;
    }

    /**
     * Le catalogue de l'amorçage (ING-008) : le propriétaire du catalogue et `$baseIngredients` ingrédients « de base » à lui, dont un sur deux
     * a deux conversions propres, créés en une fois par la base (`generate_series`). Un second appel n'ajoute rien.
     */
    public function savedSeedingCatalog(int $baseIngredients): void
    {
        $connection = $this->entityManager->getConnection();
        if ((int) $connection->fetchOne("SELECT count(*) FROM ingredient WHERE slug LIKE 'base-%'") > 0) {
            return;
        }

        $owner = new UserBuilder()->isCatalogOwner()->build();
        $this->persister->saveUser($owner);
        $connection->executeStatement(
            "INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from, is_base)"
            . " SELECT 'base-' || lpad(g::text, 3, '0'), 'Base ' || lpad(g::text, 3, '0'), 'images/x.png', 'base-' || lpad(g::text, 3, '0'), 'rayon-' || (g % 12), 'gramme', 'kilo', ?, '[\"gramme\",\"kilo\"]', NULL, TRUE"
            . ' FROM generate_series(1, ?) g',
            [$owner->getId(), $baseIngredients],
        );
        $connection->executeStatement(
            "INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)"
            . " SELECT gen_random_uuid()::text, u.start_unit, u.end_unit, 10 + g, 'base-' || lpad(g::text, 3, '0')"
            . " FROM generate_series(1, ?) g, (VALUES ('unite', 'gramme'), ('tasse', 'gramme')) AS u(start_unit, end_unit) WHERE g % 2 = 0",
            [$baseIngredients],
        );
    }

    /**
     * Deux ingrédients voisins de `$owner` (« Tomate », gardé, et « Tomates », absorbé, chacun avec sa conversion propre) et un volume de
     * recettes, de listes de courses et de choix du drive qui utilisent « Tomates » ; renvoie la commande qui fusionne.
     *
     * Avec `$recoveredConversions` > 0, « Tomates » sait en plus compter autant d'unités (la tranche, la boîte, le bloc, la cuillère à soupe) que
     * « Tomate » ne connaît pas : elles sont récupérées, et une recette de « Tomates » en tranches (unité qu'il déclare compatible) fait gagner
     * la tranche aux unités compatibles de « Tomate ».
     */
    public function savedMerge(User $owner, int $recipes, int $lists, int $recoveredConversions = 0): MergeIngredientsCommand
    {
        $dataset = new MergeDataset($this->entityManager, $this->persister);
        $dataset->units();
        $kept = $dataset->ingredient('Tomate', $owner);
        $absorbed = $dataset->ingredient('Tomates', $owner, compatible: $recoveredConversions > 0 ? [new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isSlice()] : null);
        $dataset->conversion($kept);
        $dataset->conversion($absorbed);
        $dataset->volume($owner, $kept, $absorbed, $recipes, $lists);
        if ($recoveredConversions > 0) {
            foreach (array_slice([new UnitBuilder()->isSlice(), new UnitBuilder()->isBox(), new UnitBuilder()->isBlock(), new UnitBuilder()->isSoupSpoon()], 0, $recoveredConversions) as $unit) {
                $this->persister->saveUnit($unit->build());
                $dataset->conversion($absorbed, 25.0, $unit, new UnitBuilder()->isGramme());
            }
            $dataset->recipe('recovery-recipe-' . substr($owner->getId(), 0, 8), $owner->getId(), [[$absorbed->getSlug(), 4.0, 'tranche']]);
        }

        return new MergeIngredientsCommand($owner->getId(), $absorbed->getSlug(), $kept->getSlug());
    }

    /**
     * Les ingrédients de test par défaut (propriétaire `IngredientBuilder::DEFAULT_CREATOR_ID`) passent au propriétaire du catalogue `$ownerId` :
     * son catalogue, d'où l'amorçage copie les ingrédients « de base » vers un compte.
     */
    public function giveDefaultIngredientsToTheCatalogOwner(string $ownerId): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE ingredient SET creator = ? WHERE creator = ?', [$ownerId, IngredientBuilder::DEFAULT_CREATOR_ID]);
        $this->entityManager->clear();
    }

    /**
     * @param bool $ownedByAuthor les ingrédients de la recette sont à son auteur (le monde sans ingrédient commun, ADR 0028 amendement) ; faux,
     *        ils gardent le propriétaire de test par défaut (`IngredientBuilder::DEFAULT_CREATOR_ID`), de quoi les donner au propriétaire du catalogue
     *        (`giveDefaultIngredientsToTheCatalogOwner()`)
     */
    public function savedRecipe(User $author, RecipeBuilder $builder, bool $ownedByAuthor = true): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        if ($ownedByAuthor) {
            foreach ($recipe->getRows() as $row) {
                $row->getIngredient()->setCreatorId($author->getId());
            }
        }
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * Liste de courses enregistrée avec un repas par recette et les lignes de courses correspondantes (comme après
     * `PlannedMeal`, sans conversion d'unité) ; `$configure` peut régler le builder (statut, nom…).
     *
     * @param list<Recipe> $recipes
     * @param (callable(ShoppingListBuilder): ShoppingListBuilder)|null $configure
     * @param (callable(ShoppingList): void)|null $beforeSave modifie la liste construite avant son enregistrement
     */
    public function savedShoppingList(User $owner, array $recipes, ?callable $configure = null, ?callable $beforeSave = null): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withOwner($owner);
        foreach ($recipes as $recipe) {
            $builder->addRecipe($recipe);
        }
        if ($configure !== null) {
            $builder = $configure($builder);
        }
        $shoppingList = $builder->build();
        foreach ($recipes as $recipe) {
            foreach ($recipe->getRows() as $row) {
                $shoppingList->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
            }
        }
        if ($beforeSave !== null) {
            $beforeSave($shoppingList);
        }
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);

        return $shoppingList;
    }

    /**
     * Les trois recettes d'une liste « typique » : carbonara, œufs à la coque et gâteau basque, avec les conversions
     * d'unité (unité d'achat <-> unité de la recette) nécessaires pour planifier leurs repas.
     *
     * @return list<Recipe>
     */
    public function typicalRecipes(User $author, bool $ownedByAuthor = true): array
    {
        $recipes = [
            $this->savedRecipe($author, new RecipeBuilder()->isPastaCarbonara(), $ownedByAuthor),
            $this->savedRecipe($author, new RecipeBuilder()->isSoftBoiledEggs(), $ownedByAuthor),
            $this->savedRecipe($author, new RecipeBuilder()->isBasqueCake(), $ownedByAuthor),
        ];
        $this->savedConversionsFor($recipes);

        return $recipes;
    }

    /**
     * @param list<Recipe> $recipes
     */
    public function savedConversionsFor(array $recipes): void
    {
        $saved = [];
        foreach ($recipes as $recipe) {
            foreach ($recipe->getRows() as $row) {
                $ingredient = $row->getIngredient();
                $purchaseUnit = $ingredient->getDefaultPurchaseUnit();
                $key = $ingredient->getSlug() . '|' . $row->getUnit()->getSlug();
                if ($row->getUnit()->isEqual($purchaseUnit) || isset($saved[$key])) {
                    continue;
                }
                $saved[$key] = true;
                $this->persister->saveConversion(
                    new ConversionBuilder()
                        ->withIngredient($ingredient)
                        ->withStartUnit($purchaseUnit)
                        ->withEndUnit($row->getUnit())
                        ->withCoefficient(100.0)
                        ->build()
                );
            }
        }
    }
}
