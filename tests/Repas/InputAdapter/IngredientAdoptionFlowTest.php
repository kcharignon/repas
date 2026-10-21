<?php

namespace Repas\Tests\Repas\InputAdapter;


use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Adopter un commun avec les vrais bus, la vraie base et les listeners (ADR 0028, ING-001). Aucun controller n'appelle encore
 * `AdoptCommonIngredient` (ING-002 le fera à la volée) : le parcours envoie la commande au bus, puis lit et supprime la copie par
 * l'API, comme le ferait l'utilisateur. Une adoption n'est pas une création : ni événement, ni statistique.
 */
class IngredientAdoptionFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        $this->persister->saveUnit(new UnitBuilder()->isGramme()->build());
    }

    public function testAnAdoptionIsAPersonalIngredientThatIsNotCountedAsCreatedNorUncountedWhenDeleted(): void
    {
        // Given : un commun avec deux conversions propres, une conversion commune, et un utilisateur qui n'a rien créé
        $user = $this->savedUser();
        $this->savedCommonButter();

        // When
        $slug = $this->adopt($user, 'beurre');

        // Then : une copie personnelle de l'utilisateur, avec ses conversions et son alias
        $this->assertSame('beurre' . $user->getId(), $slug);
        $this->assertSame([$user->getId(), 'beurre'], $this->row('SELECT creator, adopted_from FROM ingredient WHERE slug = ?', $slug));
        $this->assertSame(2, $this->rows('conversion', 'ingredient', $slug));
        $this->assertSame(2, $this->rows('conversion', 'ingredient', 'beurre'));
        $this->assertSame([$slug, 'adoption', 'first-use'], $this->row('SELECT ingredient_slug, kind, source FROM ingredient_alias WHERE user_id = ? AND alias_slug = ?', $user->getId(), 'beurre'));
        // Aucun événement : le compteur « ingrédients créés » n'a pas bougé
        $this->assertSame(0, $this->createdIngredients($user));

        // When : rejouée
        $again = $this->adopt($user, 'beurre');

        // Then : le même slug, rien de plus
        $this->assertSame($slug, $again);
        $this->assertSame(1, $this->rows('ingredient', 'adopted_from', 'beurre'));
        $this->assertSame(2, $this->rows('conversion', 'ingredient', $slug));
        $this->assertSame(1, $this->rows('ingredient_alias', 'user_id', $user->getId()));

        // When : l'utilisateur lit sa copie, comme n'importe lequel de ses ingrédients personnels
        $this->request('GET', '/api/v1/ingredients/' . $slug, $user);

        // Then : visible et modifiable par lui seul
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([$slug, true], [$this->jsonBody()['slug'], $this->jsonBody()['canEdit']]);
        $this->request('GET', '/api/v1/ingredients/' . $slug, $this->savedUser());
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        // When : il la supprime (rien ne l'utilise)
        $this->request('DELETE', '/api/v1/ingredients/' . $slug, $user);

        // Then : la copie, ses conversions et son alias disparaissent, le commun et les siennes restent, le compteur ne descend pas
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rows('ingredient', 'slug', $slug));
        $this->assertSame(0, $this->rows('conversion', 'ingredient', $slug));
        $this->assertSame(0, $this->rows('ingredient_alias', 'user_id', $user->getId()));
        $this->assertSame(1, $this->rows('ingredient', 'slug', 'beurre'));
        $this->assertSame(2, $this->rows('conversion', 'ingredient', 'beurre'));
        $this->assertSame(0, $this->createdIngredients($user));

        // When : il adopte de nouveau le commun
        $readopted = $this->adopt($user, 'beurre');

        // Then : le commun est redevenu adoptable, avec le même slug
        $this->assertSame($slug, $readopted);
    }

    public function testAnIngredientCreatedByTheUserIsCountedAndUncountedWhenDeleted(): void
    {
        // Given
        $user = $this->savedUser();

        // When
        $this->request('POST', '/api/v1/ingredients', $user, ['name' => 'Praliné', 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme']);
        $slug = basename($this->client->getResponse()->headers->get('Location'));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->row('SELECT adopted_from FROM ingredient WHERE slug = ?', $slug)[0]);
        $this->assertSame(1, $this->createdIngredients($user));

        // When
        $this->request('DELETE', '/api/v1/ingredients/' . $slug, $user);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->createdIngredients($user));
    }

    public function testAdoptingAndCreatingTheSameNameGiveDistinctIngredientsAndNothingIsOverwritten(): void
    {
        // Given : l'utilisateur a créé son « Beurre », puis adopte le commun du même nom
        $user = $this->savedUser();
        $this->savedCommonButter();
        $this->request('POST', '/api/v1/ingredients', $user, ['name' => 'beurre', 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme']);
        $mine = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame('beurre' . $user->getId(), $mine);

        // When
        $adopted = $this->adopt($user, 'beurre');

        // Then : la copie prend le slug suivant, l'ingrédient créé reste tel quel, sans conversion ajoutée
        $this->assertSame('beurre-2' . $user->getId(), $adopted);
        $this->assertSame(['boulangerie', 'gramme', null], $this->row('SELECT department, default_purchase_unit, adopted_from FROM ingredient WHERE slug = ?', $mine));
        $this->assertSame(0, $this->rows('conversion', 'ingredient', $mine));
        $this->assertSame(2, $this->rows('conversion', 'ingredient', $adopted));
        // Un seul ingrédient créé : l'adoption ne compte pas
        $this->assertSame(1, $this->createdIngredients($user));
    }

    public function testCreatingAnIngredientWhoseSlugIsTheKeyOfAnAliasRemovesThatAliasOnly(): void
    {
        // Given : un alias de fusion garde l'ancien slug d'un ingrédient absorbé, et un autre utilisateur a le même
        $user = $this->savedUser();
        $other = $this->savedUser();
        $stale = 'praline' . $user->getId();
        $aliases = static::getContainer()->get(IngredientAliasRepository::class);
        $aliases->save(IngredientAlias::create($user->getId(), $stale, 'autre-ingredient', AliasKind::MERGE, 'merge', new DateTimeImmutable()));
        $aliases->save(IngredientAlias::create($user->getId(), 'sucre', 'sucre' . $user->getId(), AliasKind::ADOPTION, 'first-use', new DateTimeImmutable()));
        $aliases->save(IngredientAlias::create($other->getId(), $stale, 'autre-ingredient', AliasKind::MERGE, 'merge', new DateTimeImmutable()));

        // When
        $this->request('POST', '/api/v1/ingredients', $user, ['name' => 'Praliné', 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme']);

        // Then : l'ingrédient recréé n'est pas détourné par l'ancien alias, les autres alias restent
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame($stale, basename($this->client->getResponse()->headers->get('Location')));
        $this->assertSame(0, $this->aliasRows('ingredient_alias', $user->getId(), $stale));
        $this->assertSame(1, $this->aliasRows('ingredient_alias', $user->getId(), 'sucre'));
        $this->assertSame(1, $this->aliasRows('ingredient_alias', $other->getId(), $stale));
    }

    public function testAPersonalIngredientCannotBeAdoptedAndNothingIsWritten(): void
    {
        // Given
        $owner = $this->savedUser();
        $personal = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $this->persister->saveIngredient($personal);
        $other = $this->savedUser();

        // Then
        $this->expectException(IngredientException::class);

        // When
        try {
            $this->adopt($other, $personal->getSlug());
        } finally {
            $this->assertSame(0, $this->rows('ingredient', 'adopted_from', $personal->getSlug()));
            $this->assertSame(0, $this->rows('ingredient_alias', 'user_id', $other->getId()));
        }
    }

    private function adopt(User $user, string $commonSlug): string
    {
        return static::getContainer()->get(CommandBusInterface::class)->dispatch(new AdoptCommonIngredientCommand($user->getId(), $commonSlug, 'first-use'));
    }

    /**
     * Le beurre commun : deux conversions propres (une plaquette et une cuillère à soupe valent des grammes), plus la conversion commune
     * kilo -> gramme qui ne doit jamais être copiée.
     */
    private function savedCommonButter(): void
    {
        // Un ancien commun : la forme de la base d'avant la passation, la seule que ce cas d'usage unitaire adopte (il disparaît avec ING-014)
        $butter = new IngredientBuilder()->isButter()->withoutCreator()->build();
        $this->persister->saveConversion(new ConversionBuilder()->isBlockToGrammeForButter()->withIngredient($butter)->build());
        $this->persister->saveConversion(
            new ConversionBuilder()->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(15)->withIngredient($butter)->build(),
        );
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function createdIngredients(User $user): int
    {
        $this->entityManager->clear();

        return (int) (static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getStatistics()['ingredients'] ?? 0);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<mixed>
     */
    private function row(string $sql, string ...$params): array
    {
        $this->entityManager->clear();

        return array_values($this->entityManager->getConnection()->fetchAssociative($sql, $params) ?: []);
    }

    private function rows(string $table, string $column, string $value): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column), [$value]);
    }

    private function aliasRows(string $table, string $userId, string $aliasSlug): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE user_id = ? AND alias_slug = ?', $table), [$userId, $aliasSlug]);
    }
}
