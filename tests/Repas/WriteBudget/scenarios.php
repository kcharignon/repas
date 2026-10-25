<?php

use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceCommand;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceCommand;
use Repas\Drive\Application\Command\SaveDriveSettings\SaveDriveSettingsCommand;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\PackSizeChange;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesCommand;
use Repas\Drive\Application\Command\SaveProductChoices\SearchQueryChange;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\SearchQuery;
use Repas\Repas\Application\Command\ActivatedShoppingList\ActivatedShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
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
use Repas\Repas\Application\Command\MarkIngredientsDistinct\MarkIngredientsDistinctCommand;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Application\Command\PausedShoppingList\PausedShoppingListCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
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
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Application\ChangeUserStatus\ChangeUserStatusCommand;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Repas\User\Application\RecordUserLogin\RecordUserLoginCommand;
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
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueCommand;
use Repas\Voice\Application\Command\ExchangeAuthorizationCode\ExchangeAuthorizationCodeCommand;
use Repas\Voice\Application\Command\IssueAuthorizationCode\IssueAuthorizationCodeCommand;
use Repas\Voice\Application\Command\RecordVoiceLinkUse\RecordVoiceLinkUseCommand;
use Repas\Voice\Application\Command\RefreshAccessGrant\RefreshAccessGrantCommand;
use Repas\Voice\Application\Command\RevokeVoiceLink\RevokeVoiceLinkCommand;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;

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
        $milk = new IngredientBuilder()->isMilk()->withCreator($owner)->build();
        $t->persister->saveIngredient($milk);

        return new AddIngredientToShoppingListCommand($owner->getId(), $milk->getSlug());
    },
    // Commande vocale (VOX-103) : la ligne arrive dans la liste active, ou dans une liste créée faute de liste active
    'ajouter-ingredient-par-la-voix' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        $milk = new IngredientBuilder()->isMilk()->withCreator($owner)->build();
        $t->persister->saveIngredient($milk);

        return new AddSpokenIngredientCommand($owner->getId(), $milk->getSlug(), UuidGenerator::new());
    },
    'ajouter-ingredient-par-la-voix-sans-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $milk = new IngredientBuilder()->isMilk()->withCreator($owner)->build();
        $t->persister->saveIngredient($milk);

        return new AddSpokenIngredientCommand($owner->getId(), $milk->getSlug(), UuidGenerator::new());
    },
    'retirer-ingredient-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $milk = new IngredientBuilder()->isMilk()->withCreator($owner)->build();
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
    // Le cas ordinaire (ADR 0028, amendement) : le copieur a reçu à son activation une copie de chacun des ingrédients de la recette (et son alias,
    // qui a pour clé le slug de l'ingrédient copié) : toutes les lignes se retrouvent par la provenance, rien n'est créé.
    'copier-recette' => static function (WriteBudgetTestCase $t): object {
        $author = $t->savedUser();
        $copier = $t->savedUser();
        $recipe = $t->savedRecipe($author, new RecipeBuilder()->isPastaCarbonara());
        $t->savedConversionsFor([$recipe]);
        foreach ($recipe->getRows() as $row) {
            $origin = $row->getIngredient();
            $copy = Ingredient::copyFromOriginal($origin, $copier->getId(), $origin->getSlug() . $copier->getId());
            $t->persister->saveIngredient($copy);
            $t->persister->saveIngredientAlias(IngredientAlias::create($copier->getId(), $origin->getSlug(), $copy->getSlug(), AliasKind::ADOPTION, 'activation', new \DateTimeImmutable()));
            if (!$row->getUnit()->isEqual($copy->getDefaultPurchaseUnit())) {
                $t->persister->saveConversion(new ConversionBuilder()->withIngredient($copy)->withStartUnit($copy->getDefaultPurchaseUnit())->withEndUnit($row->getUnit())->withCoefficient(100.0)->build());
            }
        }

        return new CopyRecipeCommand($recipe->getId(), $copier->getId(), UuidGenerator::new());
    },
    // Le copieur n'a reçu aucun de ces ingrédients : une copie pour chacun, avec ses conversions propres (une copie retient son origine, D7)
    'copier-recette-sans-ingredients' => static function (WriteBudgetTestCase $t): object {
        $author = $t->savedUser();
        $copier = $t->savedUser();
        $recipe = $t->savedRecipe($author, new RecipeBuilder()->isPastaCarbonara());
        $t->savedConversionsFor([$recipe]);

        return new CopyRecipeCommand($recipe->getId(), $copier->getId(), UuidGenerator::new());
    },
    // Les ingrédients de la recette sont lus UNE fois pour tout le copieur (candidats du rapprochement, alias), quel que soit le nombre de
    // lignes ; une copie d'ingrédient coûte ses écritures (ingrédient et conversions propres), un lien n'en coûte aucune. ING-004.
    'copier-recette-ingredient-personnel' => static function (WriteBudgetTestCase $t): object {
        $author = $t->savedUser();
        $copier = $t->savedUser();
        $butter = new IngredientBuilder()->isButter()->withCreator($author, true)->build();
        $t->persister->saveIngredient($butter);
        $t->persister->saveConversion(new ConversionBuilder()->withIngredient($butter)->withStartUnit(new UnitBuilder()->isBlock())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(250)->build());
        $recipe = $t->savedRecipe($author, new RecipeBuilder()->addRow(new RecipeRowBuilder()->withIngredient($butter)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100)));

        return new CopyRecipeCommand($recipe->getId(), $copier->getId(), UuidGenerator::new());
    },
    'copier-recette-ingredient-rapproche' => static function (WriteBudgetTestCase $t): object {
        $author = $t->savedUser();
        $copier = $t->savedUser();
        $authorsButter = new IngredientBuilder()->isButter()->withCreator($author, true)->build();
        $copiersButter = new IngredientBuilder()->isButter()->withCreator($copier, true)->build();
        foreach ([$authorsButter, $copiersButter] as $butter) {
            $t->persister->saveIngredient($butter);
            $t->persister->saveConversion(new ConversionBuilder()->withIngredient($butter)->withStartUnit(new UnitBuilder()->isBlock())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(250)->build());
        }
        $recipe = $t->savedRecipe($author, new RecipeBuilder()->addRow(new RecipeRowBuilder()->withIngredient($authorsButter)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100)));

        return new CopyRecipeCommand($recipe->getId(), $copier->getId(), UuidGenerator::new());
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

        return new CreateIngredientCommand('Praliné', '', $department->getSlug(), $unit->getSlug(), $unit->getSlug(), $owner->getId(), null);
    },
    // Un administrateur crée un ingrédient (ING-012) : comme tout le monde, à lui ; le même budget que `creer-ingredient` (le rôle ne coûte rien).
    'creer-ingredient-administrateur' => static function (WriteBudgetTestCase $t): object {
        $admin = $t->savedUser(new UserBuilder()->isAdmin());
        $department = new DepartmentBuilder()->isBakery()->build();
        $unit = new UnitBuilder()->isGramme()->build();
        $t->persister->saveDepartment($department);
        $t->persister->saveUnit($unit);

        return new CreateIngredientCommand('Praliné', '', $department->getSlug(), $unit->getSlug(), $unit->getSlug(), $admin->getId(), null);
    },
    // Le propriétaire du catalogue crée un ingrédient « de base » (ING-011) : une lecture de plus que `creer-ingredient`, celle du compte
    // propriétaire ; `creer-ingredient` (sans marqueur) ne le lit pas.
    'creer-ingredient-base' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser(new UserBuilder()->isCatalogOwner());
        $department = new DepartmentBuilder()->isBakery()->build();
        $unit = new UnitBuilder()->isGramme()->build();
        $t->persister->saveDepartment($department);
        $t->persister->saveUnit($unit);

        return new CreateIngredientCommand('Praliné', '', $department->getSlug(), $unit->getSlug(), $unit->getSlug(), $owner->getId(), null, true);
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
            $owner->getId(),
        );
    },
    // Le propriétaire du catalogue marque un ingrédient « de base » (ING-011) : une lecture de plus que `modifier-ingredient`, celle du
    // compte propriétaire (le seul cas où il est lu : sans marqueur, ou avec le même, le budget de la modification ne bouge pas).
    'modifier-ingredient-base' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser(new UserBuilder()->isCatalogOwner());
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
            $owner->getId(),
            true,
        );
    },
    'supprimer-ingredient' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $t->persister->saveIngredient($ingredient);

        return new RemoveIngredientCommand($ingredient->getSlug());
    },
    // « Pas le même » (ADR 0028 décision h, ING-006) : deux ingrédients de l'utilisateur, une ligne dans ingredient_distinct. Le nombre de
    // requêtes ne dépend de rien d'autre que de ces deux ingrédients.
    'pas-le-meme' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $tomato = new IngredientBuilder()->withName('Tomate')->withCreator($owner, true)->build();
        $tomatoes = new IngredientBuilder()->withName('Tomates')->withCreator($owner, true)->build();
        $t->persister->saveIngredient($tomato);
        $t->persister->saveIngredient($tomatoes);

        return new MarkIngredientsDistinctCommand($owner->getId(), $tomatoes->getSlug(), $tomato->getSlug());
    },
    // Amorçage des ingrédients à l'activation (ING-008, interrupteur ALLUMÉ) : la confirmation de l'adresse d'un compte en attente copie les
    // ingrédients « de base » du propriétaire du catalogue, leurs conversions propres et leurs alias, dans la transaction de l'activation. Le
    // nombre de requêtes ne dépend pas du nombre d'ingrédients (tests/Repas/WriteBudget/SeedingActivationBudgetTest, mesuré avec 5 puis 50) ;
    // interrupteur coupé les scénarios `confirmation-email` et `changer-statut-compte` gardent leur budget d'avant.
    'confirmer-adresse-amorcage' => static function (WriteBudgetTestCase $t): object {
        $t->switchOnIngredientSeeding();
        $t->savedSeedingCatalog(5);
        $user = $t->savedUser(new UserBuilder()->isPending());
        $t->savedEmailVerificationToken($user, 'le-secret');

        return new VerifyEmailCommand(EmailVerificationToken::hash('le-secret'));
    },
    // L'amorçage d'un compte SEUL (le rattrapage `repas:ingredients:seed` : une commande par compte), compte neuf, catalogue de 5 ingrédients de
    // base : c'est le coût fixe que les deux activations ci-dessus ajoutent à leur propre budget (constant en nombre d'ingrédients)
    'amorcer-compte' => static function (WriteBudgetTestCase $t): object {
        $t->savedSeedingCatalog(5);
        $user = $t->savedUser();

        return new SeedUserIngredientsCommand($user->getId(), 'rattrapage-essai');
    },
    // L'administrateur active un compte désactivé qui n'a jamais rien reçu : même amorçage
    'activer-compte-amorcage' => static function (WriteBudgetTestCase $t): object {
        $t->switchOnIngredientSeeding();
        $t->savedSeedingCatalog(5);
        $user = $t->savedUser(new UserBuilder()->isDisabled());

        return new ChangeUserStatusCommand($user->getId(), UserStatus::ACTIVE);
    },
    // Fusion de deux ingrédients de l'utilisateur (ADR 0028, ING-005) : verrou, analyse (usage, graphe de conversion, choix du drive),
    // repointage en masse des recettes, des listes et des choix, alias, suppression de l'absorbé et statistique. Le nombre de requêtes ne
    // dépend ni du nombre de recettes ni du nombre de listes (tests/Repas/WriteBudget/MergeIngredientsBudgetTest).
    'fusionner-ingredients' => static function (WriteBudgetTestCase $t): object {
        return $t->savedMerge($t->savedUser(), recipes: 3, lists: 3);
    },
    // La même fusion quand l'ingrédient gardé RÉCUPÈRE des conversions de l'absorbé (copie en une requête) et gagne une unité compatible : un nombre
    // fixe de requêtes de plus, quel nombre de conversions et quel volume (tests/Repas/WriteBudget/MergeIngredientsBudgetTest).
    'fusionner-ingredients-avec-recuperation' => static function (WriteBudgetTestCase $t): object {
        return $t->savedMerge($t->savedUser(), recipes: 3, lists: 3, recoveredConversions: 2);
    },
    'creer-conversion' => static function (WriteBudgetTestCase $t): object {
        $t->persister->saveUnit(new UnitBuilder()->isLiter()->build());
        $t->persister->saveUnit(new UnitBuilder()->isMillilitre()->build());

        return new CreateConversionCommand(UuidGenerator::new(), 'litre', 'millilitre', 1000.0, null, $t->savedUser(new UserBuilder()->isAdmin())->getId());
    },
    'modifier-conversion' => static function (WriteBudgetTestCase $t): object {
        $conversion = new ConversionBuilder()->isKiloToGramme()->build();
        $t->persister->saveConversion($conversion);

        return new UpdateConversionCommand($conversion->getId(), 'kilo', 'gramme', 1001.0, null, $t->savedUser(new UserBuilder()->isAdmin())->getId());
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
    // Dernière connexion (ADR 0021, amendement du 2026-10-20) : mesurée à froid, 2 requêtes (lecture du compte + un UPDATE ciblé de
    // `last_login_at`). Dans une vraie connexion ou un vrai renouvellement le compte vient d'être chargé par l'authentification et
    // `findOneById` est servi par l'identity map : UNE requête de plus, et ZÉRO quand la dernière connexion a moins de 10 minutes
    // (la commande n'est alors même pas envoyée : `LastLoginFlowTest` le prouve de bout en bout).
    'derniere-connexion' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();

        return new RecordUserLoginCommand($user->getId());
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
    // Le choix porte aussi la recherche qui a fait trouver le produit (DRV-650) : elle voyage dans l'écriture de la préférence, aucune
    // requête de plus (le budget est celui d'avant)
    'choix-drive' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $t->persister->saveIngredient(new IngredientBuilder()->isFlour()->build());
        $t->savedDriveChoice($user, 'farine', ['1', '2'], ['9']);

        return new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('3', 'Farine de blé T55 1 kg', null, new ProductContent(1000, ContentUnit::G), new ProductPrice(149), 4001, 4101), SearchQueryChange::set(SearchQuery::of('farine de blé t55'))),
        ]);
    },
    // Le même use case avec un lot de 41 intentions sur 11 ingrédients, dont dix déjà mémorisés et un nouveau : constant quelle que soit
    // la taille du lot, et une requête de plus que ci-dessus, le décompte des préférences (plafond par utilisateur, DRV-613), lu
    // seulement quand le lot crée une préférence. Chaque produit choisi porte un rayon de l'enseigne et les ingrédients existent : la
    // lecture des rayons de Repas et l'écriture des compteurs (DRV-642) restent deux requêtes pour tout le lot
    // Les dix produits préférés portent aussi leur recherche mémorisée (DRV-650) : aucune requête de plus
    'choix-drive-lot' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $intents = [];
        foreach (range(1, 10) as $i) {
            $slug = "ingredient-$i";
            $t->persister->saveIngredient(new IngredientBuilder()->isMilk()->withSlug($slug)->withName("Ingrédient $i")->build());
            $t->savedDriveChoice($user, $slug, [(string) $i, (string) ($i + 100)], [(string) ($i + 200)]);
            $intents[] = new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput((string) ($i + 300), "Produit $i", null, new ProductContent(500, ContentUnit::G), new ProductPrice(100 + $i), 4000 + ($i % 3), 4100), SearchQueryChange::set(SearchQuery::of("recherche $i")));
            $intents[] = new ChoiceIntent($slug, ChoiceAction::ADD_PRODUCT, Placement::FALLBACK, new ProductInput((string) ($i + 400), "Produit de secours $i"));
            $intents[] = new ChoiceIntent($slug, ChoiceAction::REJECT_PRODUCT, null, new ProductInput((string) $i, "Produit refusé $i"));
            $intents[] = new ChoiceIntent($slug, ChoiceAction::REFRESH_PRICE, null, new ProductInput((string) ($i + 100), null, null, null, new ProductPrice(200 + $i)));
        }
        $intents[] = new ChoiceIntent('sel', ChoiceAction::HAVE_AT_HOME);

        return new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', $intents);
    },
    // Le même use case quand le lot donne la contenance du produit dans l'unité de la liste (DRV-652, « 20 tranches ») : elle voyage dans la
    // réécriture des lignes de produits (aucune requête de plus pour la porter), mais l'unité doit exister dans Repas, une lecture de plus
    // (une seule quel que soit le nombre de contenances du lot, aucune quand le lot n'en donne pas : `choix-drive*` ne changent pas)
    'choix-drive-contenance' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $t->persister->saveIngredient(new IngredientBuilder()->isFlour()->build());
        $t->persister->saveUnit(new UnitBuilder()->isSlice()->build());
        $t->savedDriveChoice($user, 'farine', ['1', '2'], ['9']);

        return new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::ADD_PRODUCT, Placement::PRIMARY, new ProductInput('3', 'Farine de blé T55 1 kg', null, new ProductContent(1000, ContentUnit::G), new ProductPrice(149), 4001, 4101, PackSizeChange::set(PackSize::of(20, 'tranche'))), SearchQueryChange::set(SearchQuery::of('farine de blé t55'))),
        ]);
    },
    // La recherche seule d'un ingrédient sans préférence (DRV-653, produit pris « cette fois seulement ») : la préférence est créée sans
    // produit. Aucun produit à lire ni à écrire, aucun apprentissage de rayon, aucune lecture d'unité : moins cher qu'un `ADD_PRODUCT`
    // (pas de compteur du jour des écritures de produits). Le lot de 3 ingrédients coûte autant.
    'choix-drive-recherche' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedDriveSettings($user);
        $t->savedDriveChoice($user, 'farine', ['1', '2'], ['9']);

        return new SaveProductChoicesCommand($user->getId(), Retailer::LECLERC, '123456', [
            new ChoiceIntent('farine', ChoiceAction::NOTE_SEARCH, null, null, SearchQueryChange::set(SearchQuery::of('farine de blé t55'))),
            new ChoiceIntent('cereale', ChoiceAction::NOTE_SEARCH, null, null, SearchQueryChange::set(SearchQuery::of('céréales coussins'))),
            new ChoiceIntent('lait', ChoiceAction::NOTE_SEARCH, null, null, SearchQueryChange::set(SearchQuery::of('lait demi écrémé'))),
        ]);
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
    // Liaison de compte Alexa (VOX-202) : émettre un code d'autorisation (avec le ménage des codes expirés du compte)
    'emettre-code-alexa' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedAuthorizationCode($user, AlexaTestClient::token('old-code'), AlexaTestClient::pkce()['challenge'], '-1 day');

        return new IssueAuthorizationCodeCommand($user->getId(), AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, 'state-budget', AlexaTestClient::pkce()['challenge'], 'S256');
    },
    // Premier échange du code : la liaison est créée, avec sa paire de jetons
    'echanger-code-alexa' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $t->savedAuthorizationCode($user, AlexaTestClient::token('code-budget'), AlexaTestClient::pkce()['challenge']);

        return new ExchangeAuthorizationCodeCommand(AlexaTestClient::CLIENT_ID, AlexaTestClient::token('code-budget'), AlexaTestClient::REDIRECT_URI, AlexaTestClient::pkce()['verifier']);
    },
    // Relier de nouveau avant qu'Alexa ait utilisé la liaison : la liaison est réutilisée, sa paire de jetons est remplacée
    'echanger-code-alexa-liaison-attendue' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $link = $t->savedVoiceLink($user);
        $t->savedAccessGrant($link, AlexaTestClient::token('old-access'), AlexaTestClient::token('old-refresh'));
        $t->savedAuthorizationCode($user, AlexaTestClient::token('code-budget'), AlexaTestClient::pkce()['challenge']);

        return new ExchangeAuthorizationCodeCommand(AlexaTestClient::CLIENT_ID, AlexaTestClient::token('code-budget'), AlexaTestClient::REDIRECT_URI, AlexaTestClient::pkce()['verifier']);
    },
    // Rafraîchissement : la paire est consommée, la suivante émise, les paires périmées de la liaison effacées
    'rafraichir-jeton-alexa' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $link = $t->savedVoiceLink($user);
        $t->savedAccessGrant($link, AlexaTestClient::token('old-access'), AlexaTestClient::token('old-refresh'), '-100 days', refreshUsed: true);
        $t->savedAccessGrant($link, AlexaTestClient::token('access'), AlexaTestClient::token('refresh'));

        return new RefreshAccessGrantCommand(AlexaTestClient::token('refresh'));
    },
    // Rejeu d'un jeton de rafraîchissement déjà échangé : la liaison est révoquée, ses jetons effacés
    'rejouer-jeton-alexa' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $link = $t->savedVoiceLink($user);
        $t->savedAccessGrant($link, AlexaTestClient::token('access'), AlexaTestClient::token('refresh'), refreshUsed: true);
        $t->savedAccessGrant($link, AlexaTestClient::token('access-2'), AlexaTestClient::token('refresh-2'));

        return new RefreshAccessGrantCommand(AlexaTestClient::token('refresh'));
    },
    'retirer-liaison-alexa' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $link = $t->savedVoiceLink($user);
        $t->savedAccessGrant($link, AlexaTestClient::token('access'), AlexaTestClient::token('refresh'), refreshUsed: true);
        $t->savedAccessGrant($link, AlexaTestClient::token('access-2'), AlexaTestClient::token('refresh-2'));

        return new RevokeVoiceLinkCommand($user->getId(), $link->getId());
    },
    // Commande vocale (VOX-204) : ce que dit Alexa, de bout en bout (choix de l'ingrédient parmi ceux de l'utilisateur, puis ajout à la liste).
    // Séance ouverte, liste active : lectures du classeur (ingrédients, usage des listes et des recettes), ajout d'une ligne
    'ajouter-article-dit-a-alexa' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        $t->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($owner)->build());

        return new AddSpokenItemCommand($owner->getId(), 'du pain', true);
    },
    'ajouter-article-dit-a-alexa-sans-liste' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($owner)->build());

        return new AddSpokenItemCommand($owner->getId(), 'du pain', false);
    },
    // Introuvable : seules les lectures du classeur, aucune écriture
    'ajouter-article-dit-a-alexa-introuvable' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($owner)->build());

        return new AddSpokenItemCommand($owner->getId(), 'xyz', true);
    },
    // Mémoire de phrase (ADR 0029 décision 11, VOX-303) : l'utilisateur a choisi une proposition, la phrase est retenue pour lui. Le slug est
    // traduit par les alias (une lecture), l'ingrédient vérifié (une lecture), la ligne écrite (un upsert).
    'memoriser-phrase-alexa' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $parsley = new IngredientBuilder()->withName('persil')->withCreator($owner, true)->build();
        $t->persister->saveIngredient($parsley);

        return new RememberSpokenPhraseCommand($owner->getId(), 'de la ciboulette', $parsley->getSlug());
    },
    // Dialogue d'un produit inconnu (ADR 0029 décision 11, VOX-303). Une proposition choisie : l'article est ajouté à la liste active ET la phrase
    // est retenue (alias, vérification de l'ingrédient, upsert).
    'choisir-proposition-alexa' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        $parsley = new IngredientBuilder()->withName('persil')->withCreator($owner, true)->build();
        $t->persister->saveIngredient($parsley);
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption($parsley->getSlug(), 'persil')], 'ciboulette');

        return new AnswerSpokenDialogueCommand($owner->getId(), AlexaRequest::fromDecoded(AlexaTestRequest::chooseOption('le premier', '1')->inOpenSession()->toArray()), $state);
    },
    // L'utilisateur répond aux propositions en nommant un autre ingrédient qu'il a : choix par le classeur (une seule lecture), ajout à la liste
    // active, puis la phrase d'ORIGINE retenue pour lui (alias, vérification, upsert)
    'corriger-proposition-alexa' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        $t->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($owner)->build());
        $state = SpokenDialogueState::choose('des patates', [new DialogueOption('pate', 'pâte')], 'patates');

        return new AnswerSpokenDialogueCommand($owner->getId(), AlexaRequest::fromDecoded(AlexaTestRequest::addItem('du pain')->inOpenSession()->toArray()), $state);
    },
    // « Dans quel rayon ? » -> « les fruits et légumes » : une seule lecture des rayons du catalogue, aucune écriture
    'rayon-dit-a-alexa' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->persister->saveDepartment(new DepartmentBuilder()->isCereal()->build());
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->askingDepartment();

        return new AnswerSpokenDialogueCommand($owner->getId(), AlexaRequest::fromDecoded(AlexaTestRequest::answerDepartment('céréales', 'cereale')->inOpenSession()->toArray()), $state);
    },
    // « Je crée ciboulette, céréale, unité. C'est bon ? » -> « oui » : le produit est créé (rayon, unités, conversions compatibles, statistiques
    // du créateur) puis ajouté à la liste active
    'creer-ingredient-par-la-voix' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedShoppingList($owner, $t->typicalRecipes($owner));
        $t->persister->saveDepartment(new DepartmentBuilder()->isCereal()->build());
        $t->persister->saveUnit(new UnitBuilder()->isUnite()->build());
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->askingDepartment()
            ->withDepartment(new DialogueOption('cereale', 'céréale'))
            ->withUnit(new DialogueOption('unite', 'unité'));

        return new AnswerSpokenDialogueCommand($owner->getId(), AlexaRequest::fromDecoded(AlexaTestRequest::yes()->inOpenSession()->toArray()), $state);
    },
    // Suivi de la liaison (VOX-204), première requête d'Alexa : l'identifiant d'utilisateur Alexa est posé, l'ancienne liaison du même Echo
    // est révoquée avec ses jetons, la date de dernier usage est écrite
    'suivre-liaison-alexa-premiere-requete' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $old = $t->savedVoiceLink($user, 'link-old', 'amzn1.ask.account.TEST', new \DateTimeImmutable('-1 day'));
        $t->savedAccessGrant($old, AlexaTestClient::token('old-access'), AlexaTestClient::token('old-refresh'));
        $t->savedVoiceLink($user);

        return new RecordVoiceLinkUseCommand('link-budget', $user->getId(), 'amzn1.ask.account.TEST', null, null);
    },
    // Liaison connue dont la date date de plus de dix minutes : une seule instruction ciblée
    'suivre-liaison-alexa' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $lastUsedAt = new \DateTimeImmutable('-1 hour');
        $t->savedVoiceLink($user, 'link-budget', 'amzn1.ask.account.TEST', $lastUsedAt);

        return new RecordVoiceLinkUseCommand('link-budget', $user->getId(), 'amzn1.ask.account.TEST', 'amzn1.ask.account.TEST', $lastUsedAt);
    },
    // Liaison connue utilisée il y a une minute : aucune requête SQL (la plupart des requêtes d'Alexa)
    'suivre-liaison-alexa-recente' => static function (WriteBudgetTestCase $t): object {
        $user = $t->savedUser();
        $lastUsedAt = new \DateTimeImmutable('-1 minute');
        $t->savedVoiceLink($user, 'link-budget', 'amzn1.ask.account.TEST', $lastUsedAt);

        return new RecordVoiceLinkUseCommand('link-budget', $user->getId(), 'amzn1.ask.account.TEST', 'amzn1.ask.account.TEST', $lastUsedAt);
    },
    // Compte qui a un lien Google, une version d'application connue (DRV-637), trois recettes planifiées dans une liste (dont une est
    // copiée par un autre utilisateur), un magasin, une préférence de produits et un alias d'ingrédient (ADR 0028). La purge des données
    // vocales (VOX-201 : liaisons, jetons, codes) est une seule instruction, quel que soit leur nombre : elle compte pour une requête.
    'supprimer-compte' => static function (WriteBudgetTestCase $t): object {
        $owner = $t->savedUser();
        $t->savedUserIdentity($owner);
        $t->savedUserAppVersion($owner);
        $recipes = $t->typicalRecipes($owner);
        $t->savedShoppingList($owner, $recipes);
        $t->savedDriveSettings($owner);
        $t->savedDriveChoice($owner, 'farine', ['1', '2'], ['9']);
        $t->persister->saveIngredientAlias(IngredientAlias::create($owner->getId(), 'beurre', 'beurre' . $owner->getId(), AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()));
        $t->savedRecipe($t->savedUser(), new RecipeBuilder()->isPastaCarbonara()->withOriginalId($recipes[0]->getId()));

        return new DeleteUserCommand($owner->getId(), 'administrateur');
    },
];
