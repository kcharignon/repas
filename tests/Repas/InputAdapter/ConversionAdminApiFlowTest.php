<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * L'écran des conversions de l'administrateur (ADR 0028, amendement du 2026-10-20, D4) de bout en bout, avec les vrais bus, la vraie base et le
 * voter : un administrateur lit, crée et modifie les conversions GÉNÉRALES et celles de SES ingrédients, jamais celles des ingrédients d'un autre
 * compte, même administrateur (le propriétaire du catalogue, par exemple). Un utilisateur ordinaire n'y a pas accès du tout (403).
 */
class ConversionAdminApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $admin;
    private User $otherAdmin;
    private string $mineId;
    private string $theirsId;
    private string $generalId;
    private string $mySlug;
    private string $theirSlug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->admin = $this->savedUser(admin: true);
        $this->otherAdmin = $this->savedUser(admin: true);

        $mine = new IngredientBuilder()->isButter()->withCreator($this->admin, true)->build();
        $theirs = new IngredientBuilder()->isButter()->withCreator($this->otherAdmin, true)->build();
        $this->mySlug = $mine->getSlug();
        $this->theirSlug = $theirs->getSlug();
        $mineConversion = new ConversionBuilder()->withIngredient($mine)->withStartUnit(new UnitBuilder()->isBlock()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(250)->build();
        $theirConversion = new ConversionBuilder()->withIngredient($theirs)->withStartUnit(new UnitBuilder()->isBlock()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(240)->build();
        $general = new ConversionBuilder()->isKiloToGramme()->build();
        foreach ([$mineConversion, $theirConversion, $general] as $conversion) {
            $this->persister->saveConversion($conversion);
        }
        $this->mineId = $mineConversion->getId();
        $this->theirsId = $theirConversion->getId();
        $this->generalId = $general->getId();
    }

    public function testAnAdministratorListsTheGeneralConversionsAndTheOnesOfHisIngredientsOnly(): void
    {
        // When
        $this->request('GET', '/api/v1/conversions', $this->admin);

        // Then : la sienne et la générale, jamais celle de l'ingrédient de l'autre administrateur ; mêmes clés qu'avant
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $members = $this->jsonBody()['member'];
        $ids = array_column($members, 'id');
        sort($ids);
        $expected = [$this->mineId, $this->generalId];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(2, $this->jsonBody()['totalItems']);
        $this->assertSame(
            ['id', 'ingredientSlug', 'ingredientName', 'creatorEmail', 'startUnitSlug', 'startUnitName', 'endUnitSlug', 'endUnitName', 'coefficient'],
            array_keys($members[0]),
        );
        $byId = array_column($members, null, 'id');
        $this->assertSame([$this->mySlug, $this->admin->getEmail()], [$byId[$this->mineId]['ingredientSlug'], $byId[$this->mineId]['creatorEmail']]);
        $this->assertSame([null, null], [$byId[$this->generalId]['ingredientSlug'], $byId[$this->generalId]['creatorEmail']]);
    }

    public function testTheOtherAdministratorSeesTheOtherSide(): void
    {
        // When
        $this->request('GET', '/api/v1/conversions', $this->otherAdmin);

        // Then : symétrique
        $ids = array_column($this->jsonBody()['member'], 'id');
        sort($ids);
        $expected = [$this->theirsId, $this->generalId];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testAnAdministratorReadsHisConversionAndAGeneralOneButNotTheOneOfAnotherAccount(): void
    {
        foreach ([[$this->mineId, 200], [$this->generalId, 200], [$this->theirsId, 404]] as [$id, $status]) {
            // When
            $this->request('GET', '/api/v1/conversions/' . $id, $this->admin);

            // Then
            $this->assertSame($status, $this->client->getResponse()->getStatusCode(), $id);
        }
    }

    public function testAnAdministratorCreatesAConversionOnHisIngredientAndOnNoOneElses(): void
    {
        $body = ['startUnitSlug' => 'cuillere-a-soupe', 'endUnitSlug' => 'gramme', 'coefficient' => 15];
        $this->persister->saveUnit(new UnitBuilder()->isSoupSpoon()->build());

        // When : sur son ingrédient
        $this->request('POST', '/api/v1/conversions', $this->admin, $body + ['ingredientSlug' => $this->mySlug]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM conversion WHERE id = ? AND ingredient = ?', $created, $this->mySlug));

        // When : sur l'ingrédient d'un autre administrateur
        $this->request('POST', '/api/v1/conversions', $this->admin, $body + ['ingredientSlug' => $this->theirSlug]);

        // Then : inconnu, rien n'est écrit
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM conversion WHERE ingredient = ? AND coefficient = 15', $this->theirSlug));

        // When : une conversion générale, sans ingrédient
        $this->request('POST', '/api/v1/conversions', $this->admin, $body);

        // Then : permise à n'importe quel administrateur
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorUpdatesHisConversionAndAGeneralOneButNeverTheOneOfAnotherAccount(): void
    {
        $body = ['startUnitSlug' => 'plaquette', 'endUnitSlug' => 'gramme', 'coefficient' => 260];

        // When : la sienne, avec son ingrédient
        $this->request('PUT', '/api/v1/conversions/' . $this->mineId, $this->admin, $body + ['ingredientSlug' => $this->mySlug]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM conversion WHERE id = ? AND coefficient = 260', $this->mineId));

        // When : celle d'un autre compte, rattachée ou non à son ingrédient
        foreach ([$body + ['ingredientSlug' => $this->theirSlug], $body + ['ingredientSlug' => $this->mySlug], $body] as $payload) {
            $this->request('PUT', '/api/v1/conversions/' . $this->theirsId, $this->admin, $payload);

            // Then : inconnue, rien n'a changé
            $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        }
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM conversion WHERE id = ? AND coefficient = 240 AND ingredient = ?', $this->theirsId, $this->theirSlug));

        // When : la sienne, rattachée à l'ingrédient d'un autre
        $this->request('PUT', '/api/v1/conversions/' . $this->mineId, $this->admin, $body + ['ingredientSlug' => $this->theirSlug]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM conversion WHERE id = ? AND ingredient = ?', $this->mineId, $this->mySlug));

        // When : une conversion générale
        $this->request('PUT', '/api/v1/conversions/' . $this->generalId, $this->admin, ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 1001]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testAnOrdinaryUserHasNoAccessAtAll(): void
    {
        // Given
        $user = $this->savedUser();

        foreach ([['GET', '/api/v1/conversions'], ['GET', '/api/v1/conversions/' . $this->generalId], ['POST', '/api/v1/conversions'], ['PUT', '/api/v1/conversions/' . $this->generalId]] as [$method, $uri]) {
            // When
            $this->request($method, $uri, $user, $method === 'GET' ? null : ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 1]);

            // Then
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), "$method $uri");
        }
    }

    private function countRows(string $sql, string ...$parameters): int
    {
        $this->entityManager->clear();

        return (int) $this->entityManager->getConnection()->fetchOne($sql, $parameters);
    }

    private function savedUser(bool $admin = false): User
    {
        $user = $admin ? new UserBuilder()->isAdmin()->build() : new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
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
}
