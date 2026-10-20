<?php

namespace Repas\Tests\Shared\Infrastructure;


use Repas\Drive\Infrastructure\Entity\Choice;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\IngredientAlias;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Infrastructure\Entity\EmailVerificationToken;
use Repas\User\Infrastructure\Entity\PasswordResetToken;
use Repas\User\Infrastructure\Entity\RefreshToken;
use Repas\User\Infrastructure\Entity\UserAppVersion;
use Repas\User\Infrastructure\Entity\UserIdentity;

/**
 * Garde-fou de la suppression d'un compte (ADR 0021) : aucune clé étrangère ne lie les tables à `user`, donc supprimer un
 * compte « comme s'il n'avait jamais existé » repose sur une liste écrite à la main. Ce test échoue dès qu'une entité
 * gagne une colonne qui désigne un utilisateur : il faut alors effacer ces données à la suppression du compte
 * (`DeleteUserHandler` pour le contexte `User`, `PurgeUserDataHandler` pour `Repas`, `PurgeUserDriveDataHandler` pour `Drive`) puis ajouter la colonne ci-dessous.
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
        ShoppingList::class => ['ownerId'],
        RefreshToken::class => ['userId'],
        DriveSettings::class => ['userId'],
        Choice::class => ['userId'],
        EmailVerificationToken::class => ['userId'],
        PasswordResetToken::class => ['userId'],
        UserIdentity::class => ['userId'],
        UserAppVersion::class => ['userId'],
    ];

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
