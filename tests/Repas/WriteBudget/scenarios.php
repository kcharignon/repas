<?php

use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceCommand;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceCommand;
use Repas\Drive\Application\Command\SaveDriveSettings\SaveDriveSettingsCommand;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesCommand;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Application\Command\ActivatedShoppingList\ActivatedShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\CreateConversion\CreateConversionCommand;
use Repas\Repas\Application\Command\CreateDepartment\CreateDepartmentCommand;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\CreateRecipeType\CreateRecipeTypeCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateUnit\CreateUnitCommand;
use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListCommand;
use Repas\Repas\Application\Command\PausedShoppingList\PausedShoppingListCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListCommand;
use Repas\Repas\Application\Command\SendShoppingList\SendShoppingListCommand;
use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Application\Command\UncheckLineOnShoppingList\UncheckLineOnShoppingListCommand;
use Repas\Repas\Application\Command\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Application\Command\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Application\Command\UpdateRecipeType\UpdateRecipeTypeCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Application\Command\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitCommand;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Application\ChangeUserStatus\ChangeUserStatusCommand;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;
use Repas\User\Application\ResetPassword\ResetPasswordCommand;
use Repas\User\Application\SendEmailVerification\SendEmailVerificationCommand;
use Repas\User\Application\SendPasswordReset\SendPasswordResetCommand;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionCommand;
use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsCommand;
use Repas\User\Application\VerifyEmail\VerifyEmailCommand;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\UserStatus;

/**
 * Un scénario = un use case d'écriture mesuré sur une situation typique : un utilisateur avec trois recettes
 * (carbonara, œufs à la coque, gâteau basque) et une liste de courses active qui les planifie. La fonction crée les
 * données et renvoie la commande à exécuter.
 *
 * @return array<string, callable(WriteBudgetTestCase): object>
 */

/** Ligne de courses d'une liste, par son rang. */
$row = static fn(ShoppingList $list, int $index = 0): ShoppingListRow => array_values($list->getRows()->toArray())[$index];

/** @return Tab<CreateRecipeRowSubCommand> */
$createRows = static fn(Recipe $recipe): Tab => new Tab(
    array_map(
        static fn($r) => new CreateRecipeRowSubCommand($r->getIngredient()->getSlug(), $r->getUnit()->getSlug(), $r->getQuantity()),
        array_values($recipe->getRows()->toArray()),
    ),
    CreateRecipeRowSubCommand::class,
);

/** @return Tab<UpdateRecipeRowSubCommand> */
$updateRows = static fn(Recipe $recipe): Tab => new Tab(
    array_map(
        static fn($r) => new UpdateRecipeRowSubCommand($r->getIngredient()->getSlug(), $r->getUnit()->getSlug(), $r->getQuantity()),
        array_values($recipe->getRows()->toArray()),
    ),
    UpdateRecipeRowSubCommand::class,
);

return [
    // --- Listes de courses
    'tick-ligne' => static function (WriteBudgetTestCase $t) use ($row): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new TickLineOnShoppingListCommand($row($list)->getId());
    },
    // Dernière ligne cochée : le listener termine la liste (StoppedShoppingList)
    'tick-derniere-ligne' => static function (WriteBudgetTestCase $t) use ($row): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner), null, static function (ShoppingList $list): void {
            $rows = array_values($list->getRows()->toArray());
            foreach (array_slice($rows, 1) as $other) {
                $other->tick();
            }
        });

        return new TickLineOnShoppingListCommand($row($list)->getId());
    },
    'decocher-ligne' => static function (WriteBudgetTestCase $t) use ($row): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner), null, static function (ShoppingList $list): void {
            array_values($list->getRows()->toArray())[0]->tick();
        });

        return new UncheckLineOnShoppingListCommand($row($list)->getId());
    },
    'modifier-portions-repas' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new UpdateServingMealCommand($list->getMeals()->reset()->getId(), 6);
    },
    'planifier-repas' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        [$first, $second, $third] = $t->typicalRecipes($owner);
        $t->savedShoppingList($owner, [$first, $second]);

        return new PlannedMealCommand($owner->getId(), $third->getId(), $owner->getDefaultServing());
    },
    'retirer-repas' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        [$first] = $recipes = $t->typicalRecipes($owner);
        $t->savedShoppingList($owner, $recipes);

        return new removeMealFromPlanCommand($owner->getId(), $first->getId());
    },
    'ajouter-ingredient-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        $milk = new IngredientBuilder()->isMilk()->build();
        $t->persister->saveIngredient($milk);

        return new AddIngredientToShoppingListCommand($owner->getId(), $milk->getSlug());
    },
    'retirer-ingredient-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $milk = new IngredientBuilder()->isMilk()->build();
        $t->persister->saveIngredient($milk);
        $t->savedShoppingList($owner, $t->typicalRecipes($owner), static fn(ShoppingListBuilder $b) => $b->addIngredient($milk));

        return new RemoveIngredientToShoppingListCommand($owner->getId(), $milk->getSlug());
    },
    'terminer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new StoppedShoppingListCommand($list->getId());
    },
    'mettre-liste-en-pause' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new PausedShoppingListCommand($list->getId());
    },
    'reactiver-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner), static fn(ShoppingListBuilder $b) => $b->withStatus(ShoppingListStatus::PAUSED));

        return new ActivatedShoppingListCommand($list->getId());
    },
    'envoyer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new SendShoppingListCommand($list->getId());
    },
    // Rouvrir une liste envoyée : l'autre liste active du propriétaire est mise en pause
    'rouvrir-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $sent = $t->savedShoppingList($owner, $t->typicalRecipes($owner), null, static function (ShoppingList $list): void {
            $list->send(new DateTimeImmutable());
        });
        $t->savedShoppingList($owner, []);

        return new ReopenShoppingListCommand($sent->getId());
    },
    'renommer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new UpdateShoppingListCommand($list->getId(), 'Courses de la semaine');
    },
    'creer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();

        return new CreateShoppingListCommand(UuidGenerator::new(), $owner->getId());
    },
    'assurer-liste-active' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();

        return new EnsureActiveShoppingListCommand(UuidGenerator::new(), $owner->getId());
    },
    'supprimer-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $list = $t->savedShoppingList($owner, $t->typicalRecipes($owner));

        return new RemoveShoppingListCommand($list->getId());
    },

    // --- Recettes
    'creer-recette' => static function (WriteBudgetTestCase $t) use ($createRows): object {
        $owner = $t->savedUser();
        $model = $t->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());
        $t->savedConversionsFor([$model]); // l'enregistrement vérifie que chaque unité mène à l'unité d'achat

        return new CreateRecipeCommand(UuidGenerator::new(), 'Carbonara bis', 4, $owner->getId(), $createRows($model), $model->getType()->getSlug());
    },
    'modifier-recette' => static function (WriteBudgetTestCase $t) use ($updateRows): object {
        $owner = $t->savedUser();
        $recipe = $t->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());
        $t->savedConversionsFor([$recipe]);

        return new UpdateRecipeCommand($recipe->getId(), 'Carbonara revisitée', 4, $updateRows($recipe), $recipe->getType()->getSlug());
    },
    'copier-recette' => static function (WriteBudgetTestCase $t): object {
        $author = $t->savedUser();
        $other = $t->savedUser();
        $recipe = $t->savedRecipe($author, new RecipeBuilder()->isPastaCarbonara());

        return new CopyRecipeCommand($recipe->getId(), $other->getId(), UuidGenerator::new());
    },
    'supprimer-recette' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $recipe = $t->savedRecipe($owner, new RecipeBuilder()->isPastaCarbonara());

        return new RemoveRecipeCommand($recipe->getId());
    },

    // --- Ingrédients et conversions
    'creer-ingredient' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $department = new DepartmentBuilder()->isBakery()->build();
        $unit = new UnitBuilder()->isGramme()->build();
        $t->persister->saveDepartment($department);
        $t->persister->saveUnit($unit);

        return new CreateIngredientCommand('Praliné', '', $department->getSlug(), $unit->getSlug(), $unit->getSlug(), $owner->getId(), false, null);
    },
    'modifier-ingredient' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $t->persister->saveIngredient($ingredient);

        return new UpdateIngredientCommand(
            $ingredient->getSlug(),
            'Farine complète',
            '',
            $ingredient->getDepartment()->getSlug(),
            $ingredient->getDefaultCookingUnit()->getSlug(),
            $ingredient->getDefaultCookingUnit()->getSlug(),
            null,
        );
    },
    'supprimer-ingredient' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $t->persister->saveIngredient($ingredient);

        return new RemoveIngredientCommand($ingredient->getSlug());
    },
    'creer-conversion' => static function (WriteBudgetTestCase $t): object {
        $t->persister->saveUnit(new UnitBuilder()->isLiter()->build());
        $t->persister->saveUnit(new UnitBuilder()->isMillilitre()->build());

        return new CreateConversionCommand(UuidGenerator::new(), 'litre', 'millilitre', 1000.0, null);
    },
    'modifier-conversion' => static function (WriteBudgetTestCase $t): object {
        $conversion = new ConversionBuilder()->isKiloToGramme()->build();
        $t->persister->saveConversion($conversion);

        return new UpdateConversionCommand($conversion->getId(), 'kilo', 'gramme', 1001.0, null);
    },

    // --- Référentiels
    'creer-unite' => static fn(WriteBudgetTestCase $t): object => new CreateUnitCommand('Pincée', 'pin'),
    'modifier-unite' => static function (WriteBudgetTestCase $t): object {
        $unit = new UnitBuilder()->isGramme()->build();
        $t->persister->saveUnit($unit);

        return new UpdateUnitCommand($unit->getSlug(), 'Gramme', 'gr');
    },
    'creer-rayon' => static fn(WriteBudgetTestCase $t): object => new CreateDepartmentCommand('Surgelés', 'images/frozen.svg'),
    'modifier-rayon' => static function (WriteBudgetTestCase $t): object {
        $department = new DepartmentBuilder()->isBakery()->build();
        $t->persister->saveDepartment($department);

        return new UpdateDepartmentCommand($department->getSlug(), 'Boulangerie', 'images/bakery.svg');
    },
    'creer-type-recette' => static fn(WriteBudgetTestCase $t): object => new CreateRecipeTypeCommand('Apéritif', 'images/aperitif.svg', 9),
    'modifier-type-recette' => static function (WriteBudgetTestCase $t): object {
        $type = new RecipeTypeBuilder()->isMeal()->build();
        $t->persister->saveRecipeType($type);

        return new UpdateRecipeTypeCommand($type->getId(), 'Plat principal', $type->getImage(), 2);
    },

    // --- Utilisateurs
    'inscription' => static fn(WriteBudgetTestCase $t): object => new RegisterNewUserCommand(UuidGenerator::new(), 'nouveau@example.com', 'mot-de-passe', 3),
    'renvoi-confirmation-email' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser(new UserBuilder()->withEmail('attente@example.com')->isPending());
        $t->savedEmailVerificationToken($user, 'ancien-secret', minutesAgo: 5);

        return new SendEmailVerificationCommand('attente@example.com');
    },
    'confirmation-email' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser(new UserBuilder()->isPending());
        $t->savedEmailVerificationToken($user, 'le-secret');

        return new VerifyEmailCommand(EmailVerificationToken::hash('le-secret'));
    },
    'mot-de-passe-oublie-demande' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser(new UserBuilder()->withEmail('oubli@example.com'));
        $t->savedPasswordResetToken($user, 'ancien-secret', minutesAgo: 5);

        return new SendPasswordResetCommand('oubli@example.com');
    },
    // Pire cas : un compte en attente (adresse confirmée par le lien, son lien d'inscription supprimé en plus)
    'mot-de-passe-oublie-reinitialisation' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser(new UserBuilder()->isPending());
        $t->savedEmailVerificationToken($user, 'le-secret-d-inscription');
        $t->savedPasswordResetToken($user, 'le-secret');

        return new ResetPasswordCommand(PasswordResetToken::hash('le-secret'), 'nouveau-mot-de-passe');
    },
    'reglages-utilisateur' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();

        return new UpdateUserSettingsCommand($user->getId(), null, 5);
    },
    // Pire cas : une version déjà connue, remplacée par la nouvelle (DRV-637). Une seule requête : l'écriture conditionnelle
    // (le compte existe) qui verrouille la ligne du compte, sans lecture préalable. Un deuxième appel dans la minute n'arrive pas
    // jusqu'ici (limiteur, aucune requête).
    'version-application' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedUserAppVersion($user);

        return new UpdateUserAppVersionCommand($user->getId(), AppPlatform::ANDROID, '0.1.10');
    },
    // Pire cas : un compte actif connecté, avec un lien de confirmation, devient désactivé
    'changer-statut-compte' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedEmailVerificationToken($user, 'le-secret');

        return new ChangeUserStatusCommand($user->getId(), UserStatus::DISABLED);
    },
    // Pire cas : le magasin déjà enregistré est remplacé par un autre
    'reglages-drive' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);

        return new SaveDriveSettingsCommand($user->getId(), Retailer::LECLERC, '654321', 'Ville-Test', 'https://autre.leclercdrive.fr/magasin-654321-654321');
    },
    // Un ingrédient déjà mémorisé (deux produits acceptés, un refusé), un nouveau produit préféré. 10 requêtes : 7 + la mise à jour
    // du compteur du jour des écritures de produits, dans les réglages (plafond par utilisateur, DRV-613) + 2 pour l'apprentissage du
    // rayon (DRV-642) : la lecture du rayon de Repas des ingrédients et l'écriture du compteur, seulement quand un choix porte un rayon
    // de l'enseigne et que l'ingrédient a un rayon de Repas (ici : l'ingrédient existe, c'est le pire cas)
    'choix-drive' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $t->persister->saveIngredient(new IngredientBuilder()->isFlour()->build());
        $t->savedDriveChoice($user, 'farine', ['1', '2'], ['9']);

        return new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('3', 'Farine de blé T55 1 kg', null, new ProductContent(1000, ContentUnit::G), new ProductPrice(149), 4001, 4101)),
        ]);
    },
    // Le même use case avec un lot de 41 intentions sur 11 ingrédients, dont dix déjà mémorisés et un nouveau : constant quelle que soit
    // la taille du lot, et une requête de plus que ci-dessus, le décompte des préférences (plafond par utilisateur, DRV-613), lu
    // seulement quand le lot crée une préférence. Chaque produit choisi porte un rayon de l'enseigne et les ingrédients existent : la
    // lecture des rayons de Repas et l'écriture des compteurs (DRV-642) restent deux requêtes pour tout le lot
    'choix-drive-lot' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $intents = [];
        foreach (range(1, 10) as $i) {
            $slug = "ingredient-$i";
            $t->persister->saveIngredient(new IngredientBuilder()->isMilk()->withSlug($slug)->withName("Ingrédient $i")->build());
            $t->savedDriveChoice($user, $slug, [(string) $i, (string) ($i + 100)], [(string) ($i + 200)]);
            $intents[] = new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput((string) ($i + 300), "Produit $i", null, new ProductContent(500, ContentUnit::G), new ProductPrice(100 + $i), 4000 + ($i % 3), 4100));
            $intents[] = new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, Placement::FALLBACK, new ProductInput((string) ($i + 400), "Produit de secours $i"));
            $intents[] = new ChoiceIntent($slug, ChoiceAction::REJECT_PRODUCT, null, new ProductInput((string) $i, "Produit refusé $i"));
            $intents[] = new ChoiceIntent($slug, ChoiceAction::REFRESH_PRICE, null, new ProductInput((string) ($i + 100), null, null, null, new ProductPrice(200 + $i)));
        }
        $intents[] = new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME);

        return new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', $intents);
    },
    'oublier-choix-drive' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $t->savedDriveChoice($user, 'farine', ['1', '2'], ['9']);

        return new ForgetChoiceCommand($user->getId(), Retailer::LECLERC, '123456', 'farine');
    },
    // Préférence mémorisée (deux produits acceptés, un refusé) remplacée depuis l'écran de gestion : l'ordre s'inverse, un produit connu
    // d'une autre préférence est accepté, le refus change. Même budget quelle que soit la taille des listes.
    'remplacer-preference-drive' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $t->savedDriveChoice($user, 'farine', ['1', '2'], ['9']);
        $t->savedDriveChoice($user, 'sel', ['3', '4']);
        $id = static fn(string $retailerProductId): string => Product::idFor(Retailer::LECLERC, '123456', $retailerProductId);

        return new ReplaceIngredientPreferenceCommand($user->getId(), Retailer::LECLERC, '123456', 'farine', false, [$id('2'), $id('1'), $id('3')], [$id('4')]);
    },
    // La même chose pour un ingrédient sans préférence : la préférence est créée, ce qui ajoute le décompte des préférences (plafond
    // par utilisateur, DRV-613). Pire cas du use case.
    'creer-preference-drive' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $t->savedDriveChoice($user, 'farine', ['1', '2'], ['9']);
        $t->savedDriveChoice($user, 'sel', ['3', '4']);
        $id = static fn(string $retailerProductId): string => Product::idFor(Retailer::LECLERC, '123456', $retailerProductId);

        return new ReplaceIngredientPreferenceCommand($user->getId(), Retailer::LECLERC, '123456', 'sucre', false, [$id('2'), $id('3')], [$id('4')]);
    },
    // Compte qui a un lien Google, une version d'application connue (DRV-637), trois recettes planifiées dans une liste (dont une est
    // copiée par un autre utilisateur), un magasin et une préférence de produits
    'supprimer-compte' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedUserIdentity($owner);
        $t->savedUserAppVersion($owner);
        $recipes = $t->typicalRecipes($owner);
        $t->savedShoppingList($owner, $recipes);
        $t->savedDriveSettings($owner);
        $t->savedDriveChoice($owner, 'farine', ['1', '2'], ['9']);
        $t->savedRecipe($t->savedUser(), new RecipeBuilder()->isPastaCarbonara()->withOriginalId($recipes[0]->getId()));

        return new DeleteUserCommand($owner->getId(), 'administrateur');
    },
];
