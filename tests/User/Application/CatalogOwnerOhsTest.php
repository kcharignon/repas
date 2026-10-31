<?php

namespace Repas\Tests\User\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryLoader\UserInMemoryLoader;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\OpenHostService\CatalogOwnerCommandOHS;
use Repas\User\Application\OpenHostService\CatalogOwnerQueryOHS;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Service\CatalogOwnerEmail;

/**
 * Le propriétaire du catalogue d'ingrédients (ADR 0028, amendement du 2026-10-20) que le contexte `User` publie (ADR 0035) : le compte
 * protégé, ou celui de la variable `INGREDIENT_CATALOG_OWNER_EMAIL` quand elle est renseignée, ou personne. Les deux côtés, écriture et
 * lecture, disent la même chose. Les handlers de `Repas` exercent aussi le côté écriture, par transitivité.
 */
class CatalogOwnerOhsTest extends TestCase
{
    private const string PROTECTED_ID = '11111111-1111-4111-8111-111111111111';
    private const string OTHER_ADMIN_ID = '22222222-2222-4222-8222-222222222222';

    /**
     * @return iterable<string, array{?string, list<User>, ?string}> la variable, les comptes, le propriétaire attendu
     */
    public static function owners(): iterable
    {
        $protected = new UserBuilder()->withId(self::PROTECTED_ID)->withEmail(User::PROTECTED_EMAIL)->isAdmin()->build();
        $other = new UserBuilder()->withId(self::OTHER_ADMIN_ID)->withEmail('demo-admin@example.com')->isAdmin()->build();
        $ordinary = new UserBuilder()->withEmail('someone@example.com')->build();

        yield 'le compte protégé, jamais « tout administrateur »' => [null, [$protected, $other, $ordinary], self::PROTECTED_ID];
        yield 'une variable vide ne surcharge rien' => ['', [$protected, $other], self::PROTECTED_ID];
        yield 'une variable faite d\'espaces ne surcharge rien' => ['   ', [$protected, $other], self::PROTECTED_ID];
        yield 'la variable désigne un autre compte (développement)' => ['demo-admin@example.com', [$protected, $other], self::OTHER_ADMIN_ID];
        yield 'la casse et les espaces de la variable n\'y changent rien' => ['  Demo-Admin@Example.com ', [$protected, $other], self::OTHER_ADMIN_ID];
        yield 'personne quand le compte n\'existe pas' => [null, [$other, $ordinary], null];
        yield 'la variable ne retombe pas sur le compte protégé' => ['missing@example.com', [$protected], null];
    }

    /**
     * @param list<User> $accounts
     */
    #[DataProvider('owners')]
    public function testBothSidesPublishTheSameOwner(?string $variable, array $accounts, ?string $expected): void
    {
        // Given
        $idByEmail = [];
        foreach ($accounts as $account) {
            $idByEmail[$account->getEmail()] = $account->getId();
        }
        $command = new CatalogOwnerCommandOHS(new UserInMemoryRepository($accounts), $variable);
        $query = new CatalogOwnerQueryOHS(new UserInMemoryLoader(idByEmail: $idByEmail), $variable);

        // Then
        $this->assertSame($expected, $command->ownerId());
        $this->assertSame($expected, $query->ownerId());
    }

    public function testTheEmailOfTheOwnerIsWrittenInOnePlaceOnly(): void
    {
        // Then : l'adresse du compte protégé vient de sa constante, normalisée comme toute adresse
        $this->assertSame(User::normalizeEmail(User::PROTECTED_EMAIL), CatalogOwnerEmail::of(null));
        $this->assertSame('demo@example.com', CatalogOwnerEmail::of(' Demo@Example.com'));
    }
}
