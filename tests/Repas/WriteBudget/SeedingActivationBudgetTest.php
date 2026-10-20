<?php

namespace Repas\Tests\Repas\WriteBudget;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Application\ChangeUserStatus\ChangeUserStatusCommand;
use Repas\User\Application\VerifyEmail\VerifyEmailCommand;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\UserStatus;

/**
 * Le budget SQL de l'amorçage à l'activation (ING-008) est CONSTANT EN NOMBRE D'INGRÉDIENTS : un catalogue de 5 ingrédients « de base » coûte
 * le même nombre de requêtes qu'un catalogue de 50 (un `INSERT … SELECT` par lot), et ce nombre est celui des scénarios
 * `confirmer-adresse-amorcage` et `activer-compte-amorcage` de `budgets.php`. Interrupteur coupé, l'activation ne coûte rien de plus.
 */
class SeedingActivationBudgetTest extends WriteBudgetTestCase
{
    #[DataProvider('catalogSizes')]
    public function testTheCostOfSeedingDoesNotDependOnTheNumberOfBaseIngredients(int $baseIngredients): void
    {
        // When : le même use case avec un catalogue de 5, puis de 50 ingrédients de base (une transaction de test par mesure)
        $count = $this->measureConfirmation($baseIngredients);

        // Then : le même nombre de requêtes, celui que fige le budget du scénario (un `INSERT … SELECT` par lot, quelle que soit la taille du catalogue)
        $this->assertSame((require __DIR__ . '/budgets.php')['confirmer-adresse-amorcage'], $count, $baseIngredients . ' ingrédients de base');
        $this->assertSame($baseIngredients, (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM ingredient WHERE adopted_from IS NOT NULL AND slug LIKE 'base-%'"));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function catalogSizes(): iterable
    {
        yield '5 ingrédients' => [5];
        yield '50 ingrédients' => [50];
    }

    public function testActivatingByTheAdministratorCostsTheSameAsConfirmingTheAddressPlusTheStatusWrite(): void
    {
        // When
        $count = $this->measure(static function (WriteBudgetTestCase $t): object {
            $t->switchOnIngredientSeeding();
            $t->savedSeedingCatalog(5);
            $user = $t->savedUser(new UserBuilder()->isDisabled());

            return new ChangeUserStatusCommand($user->getId(), UserStatus::ACTIVE);
        })[0];

        // Then
        $this->assertSame((require __DIR__ . '/budgets.php')['activer-compte-amorcage'], $count);
    }

    public function testWithTheSwitchOffTheActivationCostsExactlyWhatItCostBefore(): void
    {
        // When : les deux activations ordinaires, interrupteur coupé (celui de l'environnement de test)
        $confirmation = $this->measure(function (WriteBudgetTestCase $t): object {
            $user = $t->savedUser(new UserBuilder()->isPending());
            $t->savedEmailVerificationToken($user, 'le-secret');

            return new VerifyEmailCommand(EmailVerificationToken::hash('le-secret'));
        })[0];
        $status = $this->measure(static function (WriteBudgetTestCase $t): object {
            $user = $t->savedUser(new UserBuilder()->isDisabled());

            return new ChangeUserStatusCommand($user->getId(), UserStatus::ACTIVE);
        })[0];

        // Then : le budget de `confirmation-email` ne bouge pas, et activer un compte ne coûte pas plus que le budget de `changer-statut-compte`
        // (son pire cas, un compte actif désactivé avec un lien de confirmation, en coûte 4 ; la lecture du compte, son écriture, l'effacement
        // du lien : 3 pour une activation)
        $budgets = require __DIR__ . '/budgets.php';
        $this->assertSame($budgets['confirmation-email'], $confirmation);
        $this->assertLessThanOrEqual($budgets['changer-statut-compte'], $status);
        $this->assertSame(3, $status, 'lire le compte, l\'écrire, effacer son lien : aucune requête de l\'amorçage');
    }

    private function measureConfirmation(int $baseIngredients): int
    {
        return $this->measure(function (WriteBudgetTestCase $t) use ($baseIngredients): object {
            $t->switchOnIngredientSeeding();
            $t->savedSeedingCatalog($baseIngredients);
            $user = $t->savedUser(new UserBuilder()->isPending());
            $t->savedEmailVerificationToken($user, 'le-secret');

            return new VerifyEmailCommand(EmailVerificationToken::hash('le-secret'));
        })[0];
    }
}
