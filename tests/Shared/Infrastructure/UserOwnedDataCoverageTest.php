<?php

namespace Repas\Tests\Shared\Infrastructure;


use Repas\Drive\Infrastructure\Entity\Choice;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Drive\Infrastructure\Entity\RayonVote;
use Repas\Drive\Infrastructure\Entity\RetailerPreference;
use Repas\Repas\Infrastructure\Entity\DepartmentListType;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\IngredientAlias;
use Repas\Repas\Infrastructure\Entity\IngredientDistinct;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\RecipeComponent;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingListType;
use Repas\Repas\Infrastructure\Entity\SpokenPhraseChoice;
use Repas\Repas\Infrastructure\Entity\SpokenRecipePhraseChoice;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Infrastructure\Entity\EmailVerificationToken;
use Repas\User\Infrastructure\Entity\PasswordResetToken;
use Repas\User\Infrastructure\Entity\RefreshToken;
use Repas\User\Infrastructure\Entity\UserAppVersion;
use Repas\User\Infrastructure\Entity\UserIdentity;
use Repas\Voice\Infrastructure\Entity\AccessGrant;
use Repas\Voice\Infrastructure\Entity\AuthorizationCode;
use Repas\Voice\Infrastructure\Entity\VoiceLink;

/**
 * Garde-fou de la suppression d'un compte (ADR 0021) : aucune clé étrangère ne lie les tables à `user`, donc supprimer un
 * compte « comme s'il n'avait jamais existé » repose sur une liste écrite à la main. Ce test échoue dès qu'une entité
 * gagne une colonne qui désigne un utilisateur : il faut alors effacer ces données à la suppression du compte
 * (`DeleteUserHandler` pour le contexte `User`, `PurgeUserDataHandler` pour `Repas`, `PurgeUserDriveDataHandler` pour `Drive`, `PurgeUserVoiceDataHandler` pour `Voice`) puis ajouter la colonne ci-dessous.
 */
class UserOwnedDataCoverageTest extends DatabaseTestCase
{
    /**
     * Colonnes qui désignent un utilisateur et qui sont effacées avec lui.
     */
    private const array ERASED_WITH_THE_USER = [
        Recipe::class => ['authorId'],
        Ingredient::class => ['creatorId'],
        IngredientAlias::class => ['userId'],
        IngredientDistinct::class => ['userId'],
        ShoppingList::class => ['ownerId'],
        ShoppingListType::class => ['ownerId'],
        DepartmentListType::class => ['ownerId'],
        SpokenPhraseChoice::class => ['userId'],
        SpokenRecipePhraseChoice::class => ['userId'],
        RefreshToken::class => ['userId'],
        DriveSettings::class => ['userId'],
        Choice::class => ['userId'],
        RayonVote::class => ['userId'],
        RetailerPreference::class => ['userId'],
        EmailVerificationToken::class => ['userId'],
        PasswordResetToken::class => ['userId'],
        UserIdentity::class => ['userId'],
        UserAppVersion::class => ['userId'],
        VoiceLink::class => ['userId'],
        AuthorizationCode::class => ['userId'],
        AccessGrant::class => ['userId'],
    ];

    public function testTheSplitIntoPartsAddsNoTableAndItsColumnsLiveOnRowsThatAreErasedWithTheUser(): void
    {
        // Given : les colonnes du découpage en parties (ADR 0038 décision 26, SUB-004) et le registre des parties d'une liste
        $holders = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach (['parentSlug', 'parentRatio', 'partShares'] as $field) {
                if ($metadata->hasField($field)) {
                    $holders[$field][] = $metadata->getName();
                }
            }
        }
        ksort($holders);

        // Then : aucune table à part, rien à ajouter à la purge d'un compte : le parent et le ratio sont sur la ligne de l'ingrédient, le registre sur la
        // ligne de la liste, deux entités que la suppression d'un compte efface déjà
        $this->assertSame(['parentRatio' => [Ingredient::class], 'parentSlug' => [Ingredient::class], 'partShares' => [ShoppingList::class]], $holders);
        $this->assertArrayHasKey(Ingredient::class, self::ERASED_WITH_THE_USER);
        $this->assertArrayHasKey(ShoppingList::class, self::ERASED_WITH_THE_USER);
    }

    public function testTheComponentsOfARecipeAreATableWithoutAUserColumnErasedWithTheRecipesOfTheirAuthor(): void
    {
        // Given : les arêtes « cette recette en utilise une autre » (ADR 0040 décision 37) désignent deux RECETTES, jamais un utilisateur
        $metadata = $this->entityManager->getClassMetadata(RecipeComponent::class);

        // Then : aucune colonne `owner|author|creator|user`, donc rien à ajouter à la liste ci-dessus ; leur purge est celle des recettes de l'auteur
        // (`RecipePostgreSQLRepository::deleteByAuthor`, prouvée dans `RecipeRepositoryTest` et `UserDataPurgeRepositoryTest`), qui efface la
        // table AVANT les recettes
        $this->assertSame(['id', 'recipeId', 'componentId', 'multiplier', 'position'], $metadata->getFieldNames());
        $this->assertSame([], array_filter($metadata->getFieldNames(), static fn(string $field): bool => preg_match('/^(owner|author|creator|user)(Id)?$/i', $field) === 1));
        $this->assertArrayNotHasKey(RecipeComponent::class, self::ERASED_WITH_THE_USER);
        $this->assertArrayHasKey(Recipe::class, self::ERASED_WITH_THE_USER);
    }

    public function testEveryColumnThatPointsToAUserIsErasedWithHim(): void
    {
        // Given
        $found = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getFieldNames() as $field) {
                if (preg_match('/^(owner|author|creator|user)(Id)?$/i', $field) === 1) {
                    $found[$metadata->getName()][] = $field;
                }
            }
        }

        // Then
        ksort($found);
        $expected = self::ERASED_WITH_THE_USER;
        ksort($expected);
        $this->assertSame(
            $expected,
            $found,
            "Une entité désigne un utilisateur sans que sa suppression l'efface : voir ADR 0021 et ce test.",
        );
    }
}
